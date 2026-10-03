<?php

namespace Tests\Unit\Empresas\Infrastructure\Mcp;

use App\Empresas\Domain\Entities\EmpresaCandidato;
use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use App\Empresas\Domain\Ports\McpEmpresaClient;
use App\Empresas\Infrastructure\Mcp\McpEmpresaClientRestFake;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PR3 of `complementar-entidad` — work-unit 3.7 (RED).
 *
 * RestFake is the test-only adapter for the `McpEmpresaClient` port.
 * It implements the same contract as the production `McpEmpresaClientHttp`
 * but is driven by in-memory fixtures so Feature tests don't need to
 * hit the real FastMCP server.
 */
class McpEmpresaClientRestFakeTest extends TestCase
{
    #[Test]
    public function restfake_implements_mcp_empresa_client_port(): void
    {
        $this->assertInstanceOf(McpEmpresaClient::class, new McpEmpresaClientRestFake());
    }

    #[Test]
    public function buscar_por_dominio_returns_configured_candidates(): void
    {
        $fake = new McpEmpresaClientRestFake();
        $fake->setCandidatos('acmein.com', [
            ['razon_social' => 'A', 'nit' => '1', 'fuente_origen' => 'socrata'],
            ['razon_social' => 'B', 'nit' => '2', 'fuente_origen' => 'rues'],
        ]);

        $candidates = $fake->buscarPorDominio('acmein.com');

        $this->assertCount(2, $candidates);
        $this->assertSame('A', $candidates[0]['razon_social']);
    }

    #[Test]
    public function buscar_por_dominio_matches_case_insensitively_and_trims(): void
    {
        $fake = new McpEmpresaClientRestFake();
        $fake->setCandidatos('acmein.com', [
            ['razon_social' => 'A', 'nit' => '1', 'fuente_origen' => 'socrata'],
        ]);

        $this->assertCount(1, $fake->buscarPorDominio('ACMEIN.COM'));
        $this->assertCount(1, $fake->buscarPorDominio('  acmein.com  '));
    }

    #[Test]
    public function buscar_por_dominio_returns_empty_for_unconfigured_domain(): void
    {
        $fake = new McpEmpresaClientRestFake();
        $this->assertSame([], $fake->buscarPorDominio('not-set.com'));
    }

    #[Test]
    public function consultar_enriquecida_returns_configured_payload(): void
    {
        $fake = new McpEmpresaClientRestFake();
        $fake->setEnriquecida('900123456-7', [
            'razon_social' => 'ACME',
            'nit' => '900123456-7',
            'numero_empleados' => 25,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'X',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'x',
            'sector_economico' => 'x',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('b', 64),
        ]);

        $result = $fake->consultarEnriquecida('900123456-7');

        $this->assertSame('ACME', $result['razon_social']);
        $this->assertSame(25, $result['numero_empleados']);
    }

    #[Test]
    public function consultar_enriquecida_throws_when_fixture_missing(): void
    {
        $this->expectException(RuntimeException::class);

        (new McpEmpresaClientRestFake())->consultarEnriquecida('not-set');
    }

    #[Test]
    public function set_exception_throws_once_and_clears(): void
    {
        $fake = new McpEmpresaClientRestFake();
        $fake->setException(new RuntimeException('boom'));

        try {
            $fake->buscarPorDominio('a.com');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Subsequent calls succeed (clear-on-throw).
        $this->assertSame([], $fake->buscarPorDominio('a.com'));
    }

    #[Test]
    public function call_counters_track_usage(): void
    {
        $fake = new McpEmpresaClientRestFake();
        $fake->setCandidatos('a.com', [
            ['razon_social' => 'A', 'nit' => '1', 'fuente_origen' => 'socrata'],
        ]);

        $fake->buscarPorDominio('a.com');
        $fake->buscarPorDominio('a.com');

        $this->assertSame(2, $fake->buscarCalls);
        $this->assertSame(0, $fake->consultarCalls);
    }

    #[Test]
    public function set_delay_ms_does_not_change_result(): void
    {
        $fake = new McpEmpresaClientRestFake();
        $fake->setDelayMs(0); // disabled — just exercises the path
        $fake->setCandidatos('a.com', [
            ['razon_social' => 'A', 'nit' => '1', 'fuente_origen' => 'socrata'],
        ]);

        $this->assertCount(1, $fake->buscarPorDominio('a.com'));
    }
}