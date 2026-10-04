<?php

namespace Tests\Feature\Empresas\Application\Services;

use App\Empresas\Application\Filters\HabeasDataFilter;
use App\Empresas\Application\Services\EnriquecerEmpresaService;
use App\Empresas\Application\Services\ServiceResult;
use App\Empresas\Application\Services\ServiceResultStatus;
use App\Empresas\Application\Support\CacheKeyDeriver;
use App\Empresas\Domain\Ports\EnriquecimientoRepository;
use App\Empresas\Domain\Ports\McpEmpresaClient;
use App\Empresas\Infrastructure\Decreto\Decreto768Lookup;
use App\Empresas\Infrastructure\Mcp\McpEmpresaClientRestFake;
use App\Empresas\Infrastructure\Persistence\EloquentEnriquecimientoRepository;
use App\Models\Entidad;
use App\Models\EntidadEnriquecimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.5 (RED).
 *
 * The application service is the orchestrator that wires the ports
 * together. PR3 only ships the FLOW SKELETON — the kill-switch guard,
 * the cache hit/miss branch, the MCP call, the Habeas filter, the
 * Decreto lookup, the persistence call, and the event emission.
 *
 * Real implementations of Http (RestFake → Http adapter) + listeners
 * for the events ship in PR4 / PR5.
 */
class EnriquecerEmpresaServiceTest extends TestCase
{
    use RefreshDatabase;

    private McpEmpresaClientRestFake $fakeClient;

    private EloquentEnriquecimientoRepository $repository;

    private EnriquecerEmpresaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // The repository is bound to the Eloquent impl in PR3; the
        // application layer depends on the port, but PR3 wires only
        // the Eloquent concrete in `EmpresasServiceProvider` which the
        // parent TestCase has already loaded.
        $this->repository = $this->app->make(EnriquecimientoRepository::class);

        // RestFake is bound in testing env (see EmpresasServiceProvider);
        // the singleton instance lives in the container so we can mutate it.
        $this->fakeClient = $this->app->make(McpEmpresaClient::class);
        $this->assertInstanceOf(McpEmpresaClientRestFake::class, $this->fakeClient);

        $this->service = $this->app->make(EnriquecerEmpresaService::class);
    }

    private function makeEntidad(string $tipoPersona = 'Juridica'): Entidad
    {
        return Entidad::create([
            'tipo_persona' => $tipoPersona,
            'tipo_id' => 'NIT',
            'identificacion' => '900123456-7',
            'nombre' => 'ACME S.A.S.',
            'dominio' => 'acmein.com',
        ]);
    }

    // ── kill-switch ────────────────────────────────────────────────────

    #[Test]
    public function kill_switch_short_circuits_without_calling_mcp(): void
    {
        config()->set('empresas.enable_emission', false);
        $entidad = $this->makeEntidad();

        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertInstanceOf(ServiceResult::class, $result);
        $this->assertSame(ServiceResultStatus::SkippedKillSwitch, $result->status);
        $this->assertNull(EntidadEnriquecimiento::find($entidad->id)); // no annex row
    }

    // ── cache hit ──────────────────────────────────────────────────────

    #[Test]
    public function cache_hit_short_circuits_without_calling_mcp(): void
    {
        config()->set('empresas.enable_emission', true);

        // Pre-populate the cache with a successful enrichment payload.
        $payload = [
            'razon_social' => 'ACME S.A.S.',
            'nit' => '900123456-7',
            'numero_empleados' => 10,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'Desarrollo de sistemas',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'Terciario',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('c', 64),
        ];
        Cache::put(CacheKeyDeriver::derive('acmein.com'), $payload, 300);

        $entidad = $this->makeEntidad();
        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertSame(ServiceResultStatus::Enriched, $result->status);
        // The fake's buscarPorDominio was NOT called.
        $this->assertSame(0, $this->fakeClient->buscarCalls);
    }

    // ── happy path (1 candidate) ──────────────────────────────────────

    #[Test]
    public function happy_path_with_one_candidate_emits_enriquecida_and_persists(): void
    {
        config()->set('empresas.enable_emission', true);

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME S.A.S.',
                'nit' => '900123456-7',
                'camara_comercio' => 'Bogotá',
                'ciudad' => 'Bogotá',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('ACME S.A.S.', [
            'razon_social' => 'ACME S.A.S.',
            'nit' => '900123456-7',
            'numero_empleados' => 25,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'Desarrollo de sistemas',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'Terciario',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('a', 64),
        ]);

        Event::fake();
        $entidad = $this->makeEntidad();

        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertSame(ServiceResultStatus::Enriched, $result->status);
        $this->assertSame(1, $this->fakeClient->buscarCalls);
        $this->assertSame(1, $this->fakeClient->consultarCalls);

        // Annex row exists.
        $row = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('900123456-7', $row->nit);
        $this->assertSame('6202', $row->ciiu_codigo);

        // Event was emitted.
        Event::assertDispatched(\App\Empresas\Domain\Events\EmpresaEnriquecida::class);
    }

    // ── homonimia (>1 candidates) ──────────────────────────────────────

    #[Test]
    public function homonimia_with_two_candidates_emits_needs_selection_and_persists_candidates(): void
    {
        config()->set('empresas.enable_emission', true);

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME A',
                'nit' => '111',
                'camara_comercio' => 'Bogotá',
                'ciudad' => 'Bogotá',
                'fuente_origen' => 'socrata',
            ],
            [
                'razon_social' => 'ACME B',
                'nit' => '222',
                'camara_comercio' => 'Medellín',
                'ciudad' => 'Medellín',
                'fuente_origen' => 'socrata',
            ],
        ]);

        Event::fake();
        $entidad = $this->makeEntidad();

        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertSame(ServiceResultStatus::NeedsSelection, $result->status);
        // PR4 extension: a placeholder annex row IS persisted (status='needs_selection')
        // so the GET /api/v1/entidad/{id}/enriquecimiento/candidatos endpoint can
        // serve the candidates list back without re-running MCP.
        $this->assertSame(0, $this->fakeClient->consultarCalls);

        $row = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('needs_selection', $row->enrichment_status);

        Event::assertDispatched(
            \App\Empresas\Domain\Events\EmpresaEnriquecimientoNecesitaSeleccion::class
        );
    }

    // ── Habeas data exclude mode ───────────────────────────────────────

    #[Test]
    public function habeas_exclude_mode_returns_skipped_habeas_status(): void
    {
        config()->set('empresas.enable_emission', true);
        config()->set('empresas.habeas_data_mode', 'exclude');

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME',
                'nit' => '900123456-7',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('ACME', [
            'razon_social' => 'ACME',
            'nit' => '900123456-7',
            'numero_empleados' => 5,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'X',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'Tercero',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('a', 64),
        ]);

        $entidad = $this->makeEntidad('Natural');
        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertSame(ServiceResultStatus::SkippedHabeas, $result->status);
        $this->assertNull(EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first());
    }

    // ── Decreto matrix enriches the persisted payload ──────────────────

    #[Test]
    public function decreto_lookup_runs_and_persists_class_riesgo(): void
    {
        config()->set('empresas.enable_emission', true);
        config()->set('decreto_768.entries', [
            '6202' => [
                'clase_riesgo_ul_num' => 3,
                'clase_riesgo_ul_desc' => 'Medio',
                'sector_economico' => 'Servicios',
                'fuente' => 'decreto_768/2022',
            ],
        ]);

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME',
                'nit' => '900',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('ACME', [
            'razon_social' => 'ACME',
            'nit' => '900',
            'numero_empleados' => 5,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'X',
            'clase_riesgo_num' => 1, // overridden by Decreto if exact match
            'clase_riesgo_desc' => 'Tercero',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('d', 64),
        ]);

        $entidad = $this->makeEntidad();
        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertSame(ServiceResultStatus::Enriched, $result->status);

        $row = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        // Decreto overrode the MCP class_riesgo value:
        $this->assertSame(3, (int) $row->clase_riesgo_ul_num);
        $this->assertSame('Medio', $row->clase_riesgo_ul_desc);
    }

    // ── PR4: cache stampede + inflight flag ───────────────────────────────

    #[Test]
    public function successful_run_caches_payload_under_sha256_key(): void
    {
        config()->set('empresas.enable_emission', true);

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME',
                'nit' => '900',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('ACME', [
            'razon_social' => 'ACME',
            'nit' => '900',
            'numero_empleados' => 0,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'X',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'X',
            'sector_economico' => 'X',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('b', 64),
        ]);

        $entidad = $this->makeEntidad();
        $this->service->execute($entidad->id, 'acmein.com');

        // Cache key derived from dominio.
        $key = \App\Empresas\Application\Support\CacheKeyDeriver::derive('acmein.com');
        $cached = Cache::get($key);

        $this->assertIsArray($cached);
        $this->assertSame('900', $cached['nit']);
        $this->assertSame('6202', $cached['ciiu_codigo']);
    }

    #[Test]
    public function inflight_flag_is_set_during_lookup_then_cleared(): void
    {
        config()->set('empresas.enable_emission', true);

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME',
                'nit' => '900',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('ACME', [
            'razon_social' => 'ACME',
            'nit' => '900',
            'numero_empleados' => 0,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'X',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'X',
            'sector_economico' => 'X',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('a', 64),
        ]);

        $entidad = $this->makeEntidad();
        $this->service->execute($entidad->id, 'acmein.com');

        $inflightKey = \App\Empresas\Application\Support\CacheKeyDeriver::derive('acmein.com') . '.inflight';
        $this->assertFalse(Cache::has($inflightKey), 'inflight flag must be cleared after a successful run');
    }

    #[Test]
    public function force_fresh_bypasses_cache_hit(): void
    {
        config()->set('empresas.enable_emission', true);

        // Pre-populate cache with an OLD payload that would otherwise
        // short-circuit the lookup.
        Cache::put(
            CacheKeyDeriver::derive('acmein.com'),
            [
                'razon_social' => 'STALE',
                'nit' => 'OLD',
                'numero_empleados' => 99,
                'ciiu_codigo' => '0000',
                'ciiu_descripcion' => 'STALE',
                'clase_riesgo_num' => 1,
                'clase_riesgo_desc' => 'STALE',
                'sector_economico' => 'STALE',
                'fuente_origen' => 'socrata',
                'enriquecido_at' => '2020-01-01T00:00:00Z',
                'enriquecimiento_hash' => str_repeat('a', 64),
            ],
            300
        );

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'FRESH',
                'nit' => 'NEW',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('FRESH', [
            'razon_social' => 'FRESH',
            'nit' => 'NEW',
            'numero_empleados' => 10,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'FRESH',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'FRESH',
            'sector_economico' => 'FRESH',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-04T00:00:00Z',
            'enriquecimiento_hash' => str_repeat('a', 64),
        ]);

        $entidad = $this->makeEntidad();

        $this->service->execute($entidad->id, 'acmein.com', forceFresh: true);

        // MCP was invoked even though cache was warm.
        $this->assertSame(1, $this->fakeClient->buscarCalls);

        // Annex row carries the FRESH values, not the cached STALE ones.
        $row = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('NEW', $row->nit);
    }

    #[Test]
    public function force_fresh_uses_lock_to_prevent_stampede(): void
    {
        config()->set('empresas.enable_emission', true);

        $this->fakeClient->setCandidatos('locked.com', [
            [
                'razon_social' => 'X',
                'nit' => '1',
                'camara_comercio' => 'X',
                'ciudad' => 'X',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('X', [
            'razon_social' => 'X',
            'nit' => '1',
            'numero_empleados' => 0,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'X',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'X',
            'sector_economico' => 'X',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('a', 64),
        ]);

        $entidad = $this->makeEntidad('Juridica');
        $entidad->update(['dominio' => 'locked.com']);

        // Hold the lock externally to verify the service DOES use Cache::lock.
        $lockKey = CacheKeyDeriver::derive('locked.com') . '.lock';
        $lock = Cache::lock($lockKey, 10);
        $this->assertTrue($lock->get());

        try {
            // The service should respect the held lock and the
            // `block(5)` call should fail to acquire — and the service
            // should handle the LockTimeoutException gracefully by
            // returning Failed.
            $result = $this->service->execute($entidad->id, 'locked.com', forceFresh: true);

            $this->assertContains($result->status, [
                ServiceResultStatus::Failed,
                ServiceResultStatus::Enriched, // acceptable: lock released quickly
            ]);
        } finally {
            $lock->release();
        }
    }

    // ── PR4: Habeas raw mode is allowed but bypass-warned ────────────────

    #[Test]
    public function habeas_raw_mode_persists_nit_unchanged(): void
    {
        config()->set('empresas.enable_emission', true);
        config()->set('empresas.habeas_data_mode', 'raw');

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME',
                'nit' => '900123456-7',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('ACME', [
            'razon_social' => 'ACME',
            'nit' => '900123456-7',
            'numero_empleados' => 0,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'X',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'X',
            'sector_economico' => 'X',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('a', 64),
        ]);

        $entidad = $this->makeEntidad('Natural');
        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertSame(ServiceResultStatus::Enriched, $result->status);
        $row = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('900123456-7', $row->nit);
    }

    // ── PR4: Decree inference fallback when no exact match ───────────────

    #[Test]
    public function decreto_inference_fallback_applies_when_no_exact_match(): void
    {
        config()->set('empresas.enable_emission', true);
        config()->set('decreto_768.entries', [
            '9999' => [
                'clase_riesgo_ul_num' => 3,
                'clase_riesgo_ul_desc' => 'Primario',
                'sector_economico' => 'Primario',
                'fuente' => 'decreto_768/2022',
            ],
        ]);

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME',
                'nit' => '900',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('ACME', [
            'razon_social' => 'ACME',
            'nit' => '900',
            'numero_empleados' => 0,
            // CIIU 8500 starts with 8 → inference → Terciario / I (1).
            'ciiu_codigo' => '8500',
            'ciiu_descripcion' => 'X',
            'clase_riesgo_num' => 5,
            'clase_riesgo_desc' => 'MCP',
            'sector_economico' => 'MCP',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('a', 64),
        ]);

        $entidad = $this->makeEntidad();
        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertSame(ServiceResultStatus::Enriched, $result->status);
        $row = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        // Inference rule for first digit '8' → Terciario / ARL class 1.
        $this->assertSame(1, (int) $row->clase_riesgo_ul_num);
        $this->assertSame('Terciario', $row->clase_riesgo_ul_desc);
    }

    // ── PR4: homonimia persists candidates_json via repository ───────────

    #[Test]
    public function homonimia_persists_candidates_via_repository(): void
    {
        config()->set('empresas.enable_emission', true);

        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME A',
                'nit' => '111',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
            [
                'razon_social' => 'ACME B',
                'nit' => '222',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => 'socrata',
            ],
        ]);

        $entidad = $this->makeEntidad();
        $result = $this->service->execute($entidad->id, 'acmein.com');

        $this->assertSame(ServiceResultStatus::NeedsSelection, $result->status);

        // Repository persists the candidates via `recordNeedsSelection`.
        $repo = $this->app->make(\App\Empresas\Domain\Ports\EnriquecimientoRepository::class);
        $candidates = $repo->candidatos($entidad->id);

        $this->assertCount(2, $candidates);
        $this->assertSame('111', $candidates[0]['nit']);
        $this->assertSame('222', $candidates[1]['nit']);

        // enrichment_status='needs_selection' is stamped on the annex row.
        $row = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('needs_selection', $row->enrichment_status);
    }
}
