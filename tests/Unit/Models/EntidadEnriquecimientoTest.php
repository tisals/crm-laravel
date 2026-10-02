<?php

namespace Tests\Unit\Models;

use App\Models\Entidad;
use App\Models\EntidadEnriquecimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR1 of `complementar-entidad` — work-unit 1.3.
 *
 * Asserts the Eloquent model + 1:1 relation + cast contract that the
 * enrichment pipeline will rely on from PR5 onward.
 */
class EntidadEnriquecimientoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function model_belongs_to_entidad(): void
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900123456-7',
            'nombre' => 'Test S.A.S.',
        ]);

        $annex = EntidadEnriquecimiento::create([
            'entidad_id' => $entidad->id,
            'nit' => '900123456-7',
            'ciiu_codigo' => '6202',
            'enriquecido_at' => now(),
            'enriquecimiento_hash' => str_repeat('a', 64),
            'enrichment_status' => 'enriched',
        ]);

        $this->assertInstanceOf(Entidad::class, $annex->entidad);
        $this->assertSame($entidad->id, $annex->entidad->id);
    }

    #[Test]
    public function enriquecido_at_is_cast_to_carbon_datetime(): void
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900123456-8',
            'nombre' => 'Datetime S.A.S.',
        ]);

        $annex = EntidadEnriquecimiento::create([
            'entidad_id' => $entidad->id,
            'enriquecido_at' => '2026-10-02 12:34:56',
            'enriquecimiento_hash' => str_repeat('b', 64),
            'enrichment_status' => 'enriched',
        ]);

        $this->assertInstanceOf(
            \Illuminate\Support\Carbon::class,
            $annex->enriquecido_at
        );
        $this->assertSame('2026-10-02 12:34:56', $annex->enriquecido_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function entidad_exposes_has_one_enriquecimiento_relation(): void
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900123456-9',
            'nombre' => 'Relation S.A.S.',
        ]);

        $annex = EntidadEnriquecimiento::create([
            'entidad_id' => $entidad->id,
            'enriquecido_at' => now(),
            'enriquecimiento_hash' => str_repeat('c', 64),
            'enrichment_status' => 'enriched',
        ]);

        $this->assertInstanceOf(EntidadEnriquecimiento::class, $entidad->enriquecimiento);
        $this->assertSame($annex->id, $entidad->enriquecimiento->id);
    }

    #[Test]
    public function entidad_exposes_enrichment_status_attribute_defaulting_to_pending(): void
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900123456-0',
            'nombre' => 'Status S.A.S.',
        ]);

        // No annex row -> enrichment_status defaults to 'pending'.
        $this->assertSame('pending', $entidad->enrichment_status);

        $entidad->refresh();

        EntidadEnriquecimiento::create([
            'entidad_id' => $entidad->id,
            'enriquecido_at' => now(),
            'enriquecimiento_hash' => str_repeat('d', 64),
            'enrichment_status' => 'enriched',
        ]);

        $this->assertSame('enriched', $entidad->fresh()->enrichment_status);
    }
}