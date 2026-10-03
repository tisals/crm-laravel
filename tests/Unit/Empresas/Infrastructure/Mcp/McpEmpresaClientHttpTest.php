<?php

namespace Tests\Unit\Empresas\Infrastructure\Mcp;

use App\Empresas\Domain\Ports\McpEmpresaClient;
use App\Empresas\Infrastructure\Mcp\Exceptions\McpServerUnavailable;
use App\Empresas\Infrastructure\Mcp\Exceptions\NotImplementedInScaffoldException;
use App\Empresas\Infrastructure\Mcp\McpEmpresaClientHttp;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PR3 of `complementar-entidad` — work-unit 3.8 (RED).
 *
 * The production `McpEmpresaClientHttp` adapter ships in PR3 as a
 * placeholder that throws `NotImplementedInScaffoldException` for the
 * actual MCP calls. PR4 wires the real HTTP transport.
 *
 * `ensureServerAvailable()` is the only method with real behavior in
 * PR3 — it probes the configured URL and throws `McpServerUnavailable`
 * when the server is unreachable.
 */
class McpEmpresaClientHttpTest extends TestCase
{
    #[Test]
    public function implements_mcp_empresa_client_port(): void
    {
        $c = new McpEmpresaClientHttp(new Factory(), 'http://localhost:8001', 10, 3);
        $this->assertInstanceOf(McpEmpresaClient::class, $c);
    }

    #[Test]
    public function buscar_por_dominio_throws_not_implemented_in_pr3(): void
    {
        $this->expectException(NotImplementedInScaffoldException::class);

        $c = new McpEmpresaClientHttp(new Factory(), 'http://localhost:8001', 10, 3);
        $c->buscarPorDominio('acmein.com');
    }

    #[Test]
    public function consultar_enriquecida_throws_not_implemented_in_pr3(): void
    {
        $this->expectException(NotImplementedInScaffoldException::class);

        $c = new McpEmpresaClientHttp(new Factory(), 'http://localhost:8001', 10, 3);
        $c->consultarEnriquecida('900123456-7');
    }

    #[Test]
    public function ensure_server_available_throws_when_url_is_null(): void
    {
        $this->expectException(McpServerUnavailable::class);

        $c = new McpEmpresaClientHttp(new Factory(), null, 10, 3);
        $c->ensureServerAvailable();
    }

    #[Test]
    public function ensure_server_available_throws_when_url_is_empty(): void
    {
        $this->expectException(McpServerUnavailable::class);

        $c = new McpEmpresaClientHttp(new Factory(), '', 10, 3);
        $c->ensureServerAvailable();
    }
}