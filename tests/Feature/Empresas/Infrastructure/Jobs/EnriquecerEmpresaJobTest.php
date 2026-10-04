<?php

namespace Tests\Feature\Empresas\Infrastructure\Jobs;

use App\Empresas\Application\Services\EnriquecerEmpresaService;
use App\Empresas\Application\Services\ServiceResultStatus;
use App\Empresas\Domain\Events\EmpresaEnriquecimientoFailed;
use App\Empresas\Infrastructure\Jobs\EnriquecerEmpresaJob;
use App\Empresas\Infrastructure\Mcp\McpEmpresaClientRestFake;
use App\Models\Entidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR4 of `complementar-entidad` — work-unit 4.4 (RED + GREEN).
 *
 * The queued `EnriquecerEmpresaJob` is the async boundary that wires
 * the `webhooks` queue to `EnriquecerEmpresaService::execute()`.
 *
 * Verified contract (per design §10):
 *   - Queue:       'webhooks'
 *   - Tries:       3
 *   - Backoff:     [60, 120, 180] seconds
 *   - Timeout:     30s (failOnTimeout)
 *   - Payload:     { entidad_id: int, dominio: string, forceFresh?: bool }
 *   - On success:  `EnriquecerEmpresaService::execute()` runs, returns
 *                  ServiceResult, the Job does not inspect it
 *   - On failure:  emits `EmpresaEnriquecimientoFailed` event with
 *                  `entidad_id`, `motivo`, `attempts`
 */
class EnriquecerEmpresaJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Resolve via the interface so the EmpresasServiceProvider's
        // singleton binding is honored — multiple resolves return the
        // SAME fixture-laden instance.
        $client = $this->app->make(\App\Empresas\Domain\Ports\McpEmpresaClient::class);
        $this->assertInstanceOf(McpEmpresaClientRestFake::class, $client);
        $this->fakeClient = $client;
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

    // ── dispatch contract ────────────────────────────────────────────────

    #[Test]
    public function job_is_dispatched_to_webhooks_queue_with_correct_payload(): void
    {
        Queue::fake();

        EnriquecerEmpresaJob::dispatch(
            entidadId: 42,
            dominio: 'acmein.com',
        );

        Queue::assertPushedOn(
            'webhooks',
            EnriquecerEmpresaJob::class,
            function (EnriquecerEmpresaJob $job) {
                return $job->entidadId === 42
                    && $job->dominio === 'acmein.com'
                    && $job->forceFresh === false;
            }
        );
    }

    #[Test]
    public function job_carries_force_fresh_flag_when_set(): void
    {
        Queue::fake();

        EnriquecerEmpresaJob::dispatch(
            entidadId: 7,
            dominio: 'forced.com',
            forceFresh: true,
        );

        Queue::assertPushedOn(
            'webhooks',
            EnriquecerEmpresaJob::class,
            function (EnriquecerEmpresaJob $job) {
                return $job->entidadId === 7
                    && $job->dominio === 'forced.com'
                    && $job->forceFresh === true;
            }
        );
    }

    #[Test]
    public function job_uses_three_tries_with_exponential_backoff(): void
    {
        $job = new EnriquecerEmpresaJob(1, 'acmein.com');

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 120, 180], $job->backoff());
    }

    #[Test]
    public function job_uses_webhooks_queue(): void
    {
        $job = new EnriquecerEmpresaJob(1, 'acmein.com');

        $this->assertSame('webhooks', $job->queue);
    }

    #[Test]
    public function job_has_fail_on_timeout_set(): void
    {
        $job = new EnriquecerEmpresaJob(1, 'acmein.com');

        // failOnTimeout is a public int property; check it's set non-null.
        $reflection = new \ReflectionClass($job);
        $prop = $reflection->getProperty('failOnTimeout');
        $prop->setAccessible(true);

        $this->assertNotNull($prop->getValue($job));
        $this->assertGreaterThan(0, $prop->getValue($job));
    }

    #[Test]
    public function job_uses_should_queue_interface(): void
    {
        $job = new EnriquecerEmpresaJob(1, 'acmein.com');

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    // ── handle() success path ────────────────────────────────────────────

    #[Test]
    public function handle_invokes_service_execute_and_swallows_result(): void
    {
        $this->fakeClient->setCandidatos('acmein.com', [
            [
                'razon_social' => 'ACME',
                'nit' => '900',
                'camara_comercio' => 'Bogotá',
                'ciudad' => 'Bogotá',
                'fuente_origen' => 'socrata',
            ],
        ]);
        $this->fakeClient->setEnriquecida('ACME', [
            'razon_social' => 'ACME',
            'nit' => '900',
            'numero_empleados' => 10,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'Desarrollo',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'Terciario',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('e', 64),
        ]);

        $entidad = $this->makeEntidad();
$service = $this->createMock(EnriquecerEmpresaService::class);
        $service->expects($this->once())
            ->method('execute')
            ->with($entidad->id, 'acmein.com', false)
            ->willReturn(new \App\Empresas\Application\Services\ServiceResult(
                \App\Empresas\Application\Services\ServiceResultStatus::Enriched,
                new \App\Empresas\Domain\Entities\EmpresaEnriquecida(
                    razon_social: 'ACME',
                    nit: '900',
                    numero_empleados: 10,
                    ciiu_codigo: '6202',
                    ciiu_descripcion: 'Desarrollo',
                    clase_riesgo_num: 1,
                    clase_riesgo_desc: 'Terciario',
                    sector_economico: 'Servicios',
                    fuente_origen: 'socrata',
                    enriquecido_at: '2026-10-03T12:00:00Z',
                    enriquecimiento_hash: str_repeat('e', 64),
                )
            ));

        $job = new EnriquecerEmpresaJob($entidad->id, 'acmein.com');
        $job->handle($service);

        // No exception, no assertion needed beyond the mock expectation.
        $this->assertTrue(true);
    }

    // ── handle() failure path → Failed event ─────────────────────────────

    #[Test]
    public function failed_handler_emits_failed_event_with_motivo_and_attempts(): void
    {
        Event::fake();

        $entidad = $this->makeEntidad();
        $job = new EnriquecerEmpresaJob($entidad->id, 'broken.com');

        $job->failed(new \RuntimeException('MCP server returned 503'));

        Event::assertDispatched(
            EmpresaEnriquecimientoFailed::class,
            function (EmpresaEnriquecimientoFailed $e) use ($entidad) {
                return $e->entidad_id === $entidad->id
                    && $e->motivo === 'MCP server returned 503'
                    && $e->attempts === 1
                    && preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                        $e->event_id
                    ) === 1;
            }
        );
    }

    #[Test]
    public function failed_handler_attempts_attribute_defaults_to_one(): void
    {
        Event::fake();

        $job = new EnriquecerEmpresaJob(99, 'broken.com');
        $job->failed(new \RuntimeException('connection refused'));

        Event::assertDispatched(
            EmpresaEnriquecimientoFailed::class,
            function (EmpresaEnriquecimientoFailed $e) {
                return $e->attempts === 1;
            }
        );
    }

    // ── end-to-end (no fakes) ────────────────────────────────────────────

    #[Test]
    public function end_to_end_run_uses_webhooks_queue_sync_in_tests(): void
    {
        // The phpunit.xml sets QUEUE_CONNECTION=sync so the job runs
        // synchronously and we can inspect the side effects directly.
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
            'clase_riesgo_desc' => 'Tercero',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('f', 64),
        ]);

        $entidad = $this->makeEntidad();

        EnriquecerEmpresaJob::dispatchSync(
            entidadId: $entidad->id,
            dominio: 'acmein.com',
        );

        // Side effect: the EnriquecerEmpresaService ran and persisted
        // an annex row.
        $row = \App\Models\EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('900', $row->nit);
    }
}