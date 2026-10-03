<?php

namespace Tests\Unit\Empresas\Application\Filters;

use App\Empresas\Application\Filters\HabeasDataFilter;
use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.6 (RED).
 *
 * Verifies the three-mode behaviour of the Habeas Data filter:
 *   - `mask`:    NIT becomes `MASKED-CC-{last4}`; PII fields null
 *   - `exclude`: returns null
 *   - `raw`:     returns the payload unchanged
 *
 * The `tipo_persona` argument short-circuits the filter for
 * `Juridica` records regardless of mode.
 */
class HabeasDataFilterTest extends TestCase
{
    private function payload(): EmpresaEnriquecida
    {
        return EmpresaEnriquecida::fromArray([
            'razon_social' => 'ACME S.A.S.',
            'nit' => '1234567890',
            'numero_empleados' => 10,
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

    #[Test]
    public function mask_replaces_nit_with_last4_digits(): void
    {
        $f = new HabeasDataFilter();
        $r = $f->apply($this->payload(), 'Natural', HabeasDataFilter::MODE_MASK);

        $this->assertNotNull($r);
        $this->assertSame('MASKED-CC-7890', $r->nit);
        // other fields preserved
        $this->assertSame('ACME S.A.S.', $r->razon_social);
        $this->assertSame('6202', $r->ciiu_codigo);
    }

    #[Test]
    public function mask_handles_nit_with_dash_suffix(): void
    {
        $p = EmpresaEnriquecida::fromArray([
            'razon_social' => 'X',
            'nit' => '900123456-7',
            'numero_empleados' => 0,
            'ciiu_codigo' => '0000',
            'ciiu_descripcion' => 'n/a',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'x',
            'sector_economico' => 'x',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T00:00:00Z',
            'enriquecimiento_hash' => str_repeat('0', 64),
        ]);

        $f = new HabeasDataFilter();
        $r = $f->apply($p, 'Natural', HabeasDataFilter::MODE_MASK);

        $this->assertSame('MASKED-CC-4567', $r->nit);
    }

    #[Test]
    public function exclude_returns_null(): void
    {
        $f = new HabeasDataFilter();
        $r = $f->apply($this->payload(), 'Natural', HabeasDataFilter::MODE_EXCLUDE);

        $this->assertNull($r);
    }

    #[Test]
    public function raw_returns_payload_unchanged(): void
    {
        $f = new HabeasDataFilter();
        $p = $this->payload();
        $r = $f->apply($p, 'Natural', HabeasDataFilter::MODE_RAW);

        $this->assertNotNull($r);
        $this->assertSame($p->nit, $r->nit);
        $this->assertSame('1234567890', $r->nit);
    }

    #[Test]
    public function juridica_passthrough_regardless_of_mode(): void
    {
        $f = new HabeasDataFilter();

        // mask mode + Juridica → no masking
        $p = $this->payload();
        $r = $f->apply($p, 'Juridica', HabeasDataFilter::MODE_MASK);
        $this->assertNotNull($r);
        $this->assertSame('1234567890', $r->nit);

        // exclude mode + Juridica → no exclusion
        $r2 = $f->apply($p, 'Juridica', HabeasDataFilter::MODE_EXCLUDE);
        $this->assertNotNull($r2);
        $this->assertSame('1234567890', $r2->nit);
    }

    #[Test]
    public function unknown_falls_back_to_mask(): void
    {
        $f = new HabeasDataFilter();
        $r = $f->apply($this->payload(), 'Natural', 'bogus-mode');

        $this->assertNotNull($r);
        $this->assertSame('MASKED-CC-7890', $r->nit);
    }
}