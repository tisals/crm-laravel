<?php

namespace Tests\Feature\Empresas\Infrastructure\Persistence;

use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use App\Empresas\Domain\Ports\EnriquecimientoRepository;
use App\Empresas\Infrastructure\Persistence\EloquentEnriquecimientoRepository;
use App\Models\Entidad;
use App\Models\EntidadEnriquecimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.9 (RED).
 *
 * Verifies the Eloquent adapter fulfils the repository port:
 *   - upsert: idempotent insert-or-update keyed by entidad_id
 *   - findByEntidadId: returns the row or null
 *   - candidatos: returns the stored candidate list
 *   - needsSelection: reads the enrichment_status column
 */
class EloquentEnriquecimientoRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeEntidad(): Entidad
    {
        return Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900123456-7',
            'nombre' => 'ACME S.A.S.',
            'dominio' => 'acmein.com',
        ]);
    }

    private function makePayload(): EmpresaEnriquecida
    {
        return EmpresaEnriquecida::fromArray([
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
    }

    private function repo(): EnriquecimientoRepository
    {
        return $this->app->make(EnriquecimientoRepository::class);
    }

    #[Test]
    public function upsert_inserts_a_new_annex_row(): void
    {
        $entidad = $this->makeEntidad();
        $payload = $this->makePayload();

        $this->repo()->upsert($entidad->id, $payload);

        $row = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('900123456-7', $row->nit);
        $this->assertSame('6202', $row->ciiu_codigo);
        $this->assertSame('enriched', $row->enrichment_status);
    }

    #[Test]
    public function upsert_overwrites_existing_row_in_place(): void
    {
        $entidad = $this->makeEntidad();
        $this->repo()->upsert($entidad->id, $this->makePayload());

        $payload2 = EmpresaEnriquecida::fromArray([
            'razon_social' => 'ACME UPDATED',
            'nit' => '999',
            'numero_empleados' => 50,
            'ciiu_codigo' => '6203',
            'ciiu_descripcion' => 'New desc',
            'clase_riesgo_num' => 2,
            'clase_riesgo_desc' => 'Medio',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'rues',
            'enriquecido_at' => '2026-10-04T00:00:00Z',
            'enriquecimiento_hash' => str_repeat('b', 64),
        ]);

        $this->repo()->upsert($entidad->id, $payload2);

        $rows = EntidadEnriquecimiento::where('entidad_id', $entidad->id)->get();
        $this->assertCount(1, $rows, '1:1 contract MUST keep one row per entidad.');
        $this->assertSame('999', $rows->first()->nit);
    }

    #[Test]
    public function find_by_entidad_id_returns_null_when_no_row(): void
    {
        $entidad = $this->makeEntidad();
        $this->assertNull($this->repo()->findByEntidadId($entidad->id));
    }

    #[Test]
    public function find_by_entidad_id_returns_the_eloquent_model(): void
    {
        $entidad = $this->makeEntidad();
        $this->repo()->upsert($entidad->id, $this->makePayload());

        $row = $this->repo()->findByEntidadId($entidad->id);

        $this->assertInstanceOf(EntidadEnriquecimiento::class, $row);
        $this->assertSame($entidad->id, $row->entidad_id);
    }

    #[Test]
    public function candidatos_returns_empty_when_no_annex_row(): void
    {
        $entidad = $this->makeEntidad();
        $this->assertSame([], $this->repo()->candidatos($entidad->id));
    }

    #[Test]
    public function candidatos_returns_empty_when_annex_has_no_candidates(): void
    {
        $entidad = $this->makeEntidad();
        $this->repo()->upsert($entidad->id, $this->makePayload());

        $this->assertSame([], $this->repo()->candidatos($entidad->id));
    }

    #[Test]
    public function candidatos_round_trip_via_record_needs_selection(): void
    {
        $entidad = $this->makeEntidad();
        /** @var EloquentEnriquecimientoRepository $repo */
        $repo = $this->app->make(EnriquecimientoRepository::class);

        $candidates = [
            ['razon_social' => 'A', 'nit' => '111', 'fuente_origen' => 'socrata'],
            ['razon_social' => 'B', 'nit' => '222', 'fuente_origen' => 'socrata'],
        ];

        // recordNeedsSelection is a PR3-only helper on the Eloquent impl.
        if (method_exists($repo, 'recordNeedsSelection')) {
            $repo->recordNeedsSelection($entidad->id, $candidates);
        }

        $this->assertSame($candidates, $repo->candidatos($entidad->id));
    }

    #[Test]
    public function needs_selection_returns_false_when_no_annex(): void
    {
        $entidad = $this->makeEntidad();
        $this->assertFalse($this->repo()->needsSelection($entidad->id));
    }

    #[Test]
    public function needs_selection_returns_true_when_annex_status_is_needs_selection(): void
    {
        $entidad = $this->makeEntidad();
        /** @var EloquentEnriquecimientoRepository $repo */
        $repo = $this->app->make(EnriquecimientoRepository::class);

        if (method_exists($repo, 'recordNeedsSelection')) {
            $repo->recordNeedsSelection($entidad->id, [
                ['razon_social' => 'A', 'nit' => '1', 'fuente_origen' => 'socrata'],
            ]);
        }

        $this->assertTrue($repo->needsSelection($entidad->id));
    }
}