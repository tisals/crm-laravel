<?php

namespace Tests\Unit\Empresas\Domain\Entities;

use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.1 (RED).
 *
 * EmpresaEnriquecida is the final enrichment payload (post-Habeas-filter)
 * that gets persisted into `entidad_enriquecimiento`. It is pure PHP,
 * final, immutable, and exposes all 10 canonical fields required by the
 * design (§3 inventory) and the spec (entity-empresa-enrichment §R-Annex).
 *
 * The `enriquecido_at` is exposed as a Carbon-style ISO 8601 string so
 * downstream consumers (controllers, listeners) don't need Carbon imports.
 * The `enriquecimiento_hash` is a 64-char hex (SHA-256) of the payload.
 */
class EmpresaEnriquecidaTest extends TestCase
{
    #[Test]
    public function enriquecida_is_final_readonly_value_object(): void
    {
        $reflection = new \ReflectionClass(EmpresaEnriquecida::class);

        $this->assertTrue($reflection->isFinal(), 'EmpresaEnriquecida MUST be final.');

        foreach ([
            'razon_social', 'nit', 'numero_empleados', 'ciiu_codigo',
            'ciiu_descripcion', 'clase_riesgo_num', 'clase_riesgo_desc',
            'sector_economico', 'fuente_origen', 'enriquecido_at',
            'enriquecimiento_hash',
        ] as $prop) {
            $p = $reflection->getProperty($prop);
            $this->assertTrue($p->isReadOnly(), "Property {$prop} MUST be readonly.");
            $this->assertTrue($p->isPublic(), "Property {$prop} MUST be public.");
        }
    }

    #[Test]
    public function named_constructor_builds_dto_from_array(): void
    {
        $hash = str_repeat('a', 64);
        $e = EmpresaEnriquecida::fromArray([
            'razon_social' => 'ACME S.A.S.',
            'nit' => '900123456-7',
            'numero_empleados' => 25,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'Desarrollo de sistemas informáticos',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'Terciario',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:34:56Z',
            'enriquecimiento_hash' => $hash,
        ]);

        $this->assertSame('ACME S.A.S.', $e->razon_social);
        $this->assertSame('900123456-7', $e->nit);
        $this->assertSame(25, $e->numero_empleados);
        $this->assertSame('6202', $e->ciiu_codigo);
        $this->assertSame(1, $e->clase_riesgo_num);
        $this->assertSame('Terciario', $e->clase_riesgo_desc);
        $this->assertSame('Servicios', $e->sector_economico);
        $this->assertSame('socrata', $e->fuente_origen);
        $this->assertSame('2026-10-03T12:34:56Z', $e->enriquecido_at);
        $this->assertSame($hash, $e->enriquecimiento_hash);
    }

    #[Test]
    public function named_constructor_rejects_unknown_fuente_origen(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EmpresaEnriquecida::fromArray([
            'razon_social' => 'X',
            'nit' => '1',
            'numero_empleados' => 0,
            'ciiu_codigo' => '0000',
            'ciiu_descripcion' => 'n/a',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'x',
            'sector_economico' => 'x',
            'fuente_origen' => 'bogus',
            'enriquecido_at' => '2026-10-03T00:00:00Z',
            'enriquecimiento_hash' => str_repeat('0', 64),
        ]);
    }

    #[Test]
    public function named_constructor_rejects_invalid_enriquecimiento_hash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('enriquecimiento_hash');

        EmpresaEnriquecida::fromArray([
            'razon_social' => 'X',
            'nit' => '1',
            'numero_empleados' => 0,
            'ciiu_codigo' => '0000',
            'ciiu_descripcion' => 'n/a',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'x',
            'sector_economico' => 'x',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T00:00:00Z',
            'enriquecimiento_hash' => 'not-a-valid-sha256',
        ]);
    }

    #[Test]
    public function named_constructor_rejects_clase_riesgo_out_of_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clase_riesgo_num');

        EmpresaEnriquecida::fromArray([
            'razon_social' => 'X',
            'nit' => '1',
            'numero_empleados' => 0,
            'ciiu_codigo' => '0000',
            'ciiu_descripcion' => 'n/a',
            'clase_riesgo_num' => 9, // valid range 1..5 per Decreto 768/2022
            'clase_riesgo_desc' => 'x',
            'sector_economico' => 'x',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T00:00:00Z',
            'enriquecimiento_hash' => str_repeat('0', 64),
        ]);
    }

    #[Test]
    public function named_constructor_accepts_clase_riesgo_5_class_values(): void
    {
        foreach ([1, 2, 3, 4, 5] as $n) {
            $e = EmpresaEnriquecida::fromArray([
                'razon_social' => 'X',
                'nit' => '1',
                'numero_empleados' => 0,
                'ciiu_codigo' => '0000',
                'ciiu_descripcion' => 'n/a',
                'clase_riesgo_num' => $n,
                'clase_riesgo_desc' => 'x',
                'sector_economico' => 'x',
                'fuente_origen' => 'socrata',
                'enriquecido_at' => '2026-10-03T00:00:00Z',
                'enriquecimiento_hash' => str_repeat('0', 64),
            ]);
            $this->assertSame($n, $e->clase_riesgo_num);
        }
    }
}