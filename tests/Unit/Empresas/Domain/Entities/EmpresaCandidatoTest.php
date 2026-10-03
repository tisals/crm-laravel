<?php

namespace Tests\Unit\Empresas\Domain\Entities;

use App\Empresas\Domain\Entities\EmpresaCandidato;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.1 (RED).
 *
 * EmpresaCandidato is a pure DTO that represents one homonymia candidate
 * returned by the MCP server's `buscarPorDominio` tool. It carries no
 * framework deps — only PHP 8.2 readonly props + a named static factory.
 *
 * The acceptance criteria are:
 *   - DTO is `final` and immutable (readonly)
 *   - Named constructor `fromArray()` produces a typed instance from the
 *     raw array the MCP returns (Socrata + RUES shapes are heterogeneous)
 *   - Every field is required (constructor takes positional args)
 *   - Defensive normalisation: lowercased `razon_social`, trimmed NIT
 *   - `fuente_origen` is a constrained string (`socrata|rues|manual`)
 */
class EmpresaCandidatoTest extends TestCase
{
    #[Test]
    public function candidato_is_final_readonly_value_object(): void
    {
        $reflection = new \ReflectionClass(EmpresaCandidato::class);

        $this->assertTrue($reflection->isFinal(), 'EmpresaCandidato MUST be final.');

        foreach (['razon_social', 'nit', 'camara_comercio', 'ciudad', 'fuente_origen'] as $prop) {
            $p = $reflection->getProperty($prop);
            $this->assertTrue($p->isReadOnly(), "Property {$prop} MUST be readonly.");
            $this->assertTrue($p->isPublic(), "Property {$prop} MUST be public.");
        }
    }

    #[Test]
    public function named_constructor_builds_dto_from_array(): void
    {
        $candidate = EmpresaCandidato::fromArray([
            'razon_social' => '  ACME S.A.S. ',
            'nit' => ' 900123456-7 ',
            'camara_comercio' => 'Bogotá',
            'ciudad' => 'Bogotá',
            'fuente_origen' => 'socrata',
        ]);

        $this->assertSame('  ACME S.A.S. ', $candidate->razon_social);
        $this->assertSame('900123456-7', $candidate->nit, 'NIT must be trimmed.');
        $this->assertSame('Bogotá', $candidate->camara_comercio);
        $this->assertSame('Bogotá', $candidate->ciudad);
        $this->assertSame('socrata', $candidate->fuente_origen);
    }

    #[Test]
    public function named_constructor_rejects_unknown_fuente_origen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fuente_origen');

        EmpresaCandidato::fromArray([
            'razon_social' => 'ACME',
            'nit' => '900',
            'camara_comercio' => 'X',
            'ciudad' => 'Y',
            'fuente_origen' => 'bogus-source',
        ]);
    }

    #[Test]
    public function named_constructor_rejects_empty_razon_social(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('razon_social');

        EmpresaCandidato::fromArray([
            'razon_social' => '',
            'nit' => '900',
            'camara_comercio' => 'X',
            'ciudad' => 'Y',
            'fuente_origen' => 'socrata',
        ]);
    }

    #[Test]
    public function named_constructor_rejects_empty_nit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nit');

        EmpresaCandidato::fromArray([
            'razon_social' => 'ACME',
            'nit' => '',
            'camara_comercio' => 'X',
            'ciudad' => 'Y',
            'fuente_origen' => 'socrata',
        ]);
    }

    #[Test]
    public function accepts_all_three_valid_fuentes(): void
    {
        foreach (['socrata', 'rues', 'manual'] as $fuente) {
            $c = EmpresaCandidato::fromArray([
                'razon_social' => 'X',
                'nit' => '1',
                'camara_comercio' => 'X',
                'ciudad' => 'Y',
                'fuente_origen' => $fuente,
            ]);
            $this->assertSame($fuente, $c->fuente_origen);
        }
    }
}