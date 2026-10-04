<?php

namespace Tests\Unit\Empresas\Infrastructure\Mcp;

use App\Empresas\Domain\Ports\McpEmpresaClient;
use App\Empresas\Infrastructure\Mcp\Exceptions\McpServerUnavailable;
use App\Empresas\Infrastructure\Mcp\Exceptions\NotImplementedInScaffoldException;
use App\Empresas\Infrastructure\Mcp\McpEmpresaClientHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR4 of `complementar-entidad` — work-unit 4.2 (RED + GREEN).
 *
 * Verifies the real `McpEmpresaClientHttp` adapter that PR3 shipped as
 * a placeholder. PR4 wires the FastMCP HTTP/SSE transport:
 *
 *   - `buscarPorDominio($dominio)` POSTs to `<url>/tools/buscar_por_dominio`
 *     with JSON body `{ dominio }` and parses the JSON array response.
 *   - `consultarEnriquecida($key)` POSTs to `<url>/tools/consultar_empresa`
 *     with JSON body `{ razon_social_o_nit }` and parses the JSON object.
 *   - 4xx responses raise `McpServerUnavailable` (caller should NOT
 *     retry — terminal failure).
 *   - 5xx responses raise `McpServerUnavailable` (delegated to the
 *     job's retry/backoff).
 *   - Connection timeouts raise `McpServerUnavailable`.
 */
class McpEmpresaClientHttpTest extends TestCase
{
    private function makeClient(string $url = 'http://mcp.local:8000', int $timeout = 5): McpEmpresaClientHttp
    {
        // Use the container-bound HttpFactory so Http::fake() can intercept.
        return new McpEmpresaClientHttp($this->app->make(Factory::class), $url, $timeout, 3);
    }

    #[Test]
    public function implements_mcp_empresa_client_port(): void
    {
        $c = new McpEmpresaClientHttp($this->app->make(Factory::class), 'http://localhost:8001', 10, 3);
        $this->assertInstanceOf(McpEmpresaClient::class, $c);
    }

    // ── buscarPorDominio ─────────────────────────────────────────────────

    #[Test]
    public function buscar_por_dominio_posts_to_tools_endpoint_and_parses_array(): void
    {
        Http::fake([
            '*buscar_por_dominio*' => Http::response([
                ['razon_social' => 'ACME', 'nit' => '900123456-7', 'fuente_origen' => 'socrata'],
                ['razon_social' => 'ACME B', 'nit' => '111', 'fuente_origen' => 'rues'],
            ], 200),
        ]);

        $client = $this->makeClient();
        $r = $client->buscarPorDominio('acmein.com');

        $this->assertCount(2, $r);
        $this->assertSame('ACME', $r[0]['razon_social']);
        $this->assertSame('900123456-7', $r[0]['nit']);

        Http::assertSent(function (Request $req) {
            return $req->url() === 'http://mcp.local:8000/tools/buscar_por_dominio'
                && $req->method() === 'POST'
                && $req->data() === ['dominio' => 'acmein.com'];
        });
    }

    #[Test]
    public function buscar_por_dominio_returns_empty_array_when_response_is_empty_object(): void
    {
        Http::fake([
            '*buscar_por_dominio*' => Http::response([], 200),
        ]);

        $client = $this->makeClient();
        $r = $client->buscarPorDominio('empty.com');

        $this->assertSame([], $r);
    }

    #[Test]
    public function buscar_por_dominio_throws_on_4xx(): void
    {
        Http::fake([
            '*buscar_por_dominio*' => Http::response('Bad Request', 400),
        ]);

        $client = $this->makeClient();

        $this->expectException(McpServerUnavailable::class);
        $this->expectExceptionMessageMatches('/400/');

        $client->buscarPorDominio('bad.com');
    }

    #[Test]
    public function buscar_por_dominio_throws_on_5xx(): void
    {
        Http::fake([
            '*buscar_por_dominio*' => Http::response('Internal Server Error', 503),
        ]);

        $client = $this->makeClient();

        $this->expectException(McpServerUnavailable::class);
        $this->expectExceptionMessageMatches('/503/');

        $client->buscarPorDominio('down.com');
    }

    #[Test]
    public function buscar_por_dominio_throws_on_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $client = $this->makeClient();

        $this->expectException(McpServerUnavailable::class);

        $client->buscarPorDominio('timeout.com');
    }

    // ── consultarEnriquecida ─────────────────────────────────────────────

    #[Test]
    public function consultar_enriquecida_posts_to_tools_endpoint_and_parses_object(): void
    {
        Http::fake([
            '*consultar_empresa*' => Http::response([
                'razon_social' => 'ACME',
                'nit' => '900123456-7',
                'numero_empleados' => 25,
                'ciiu_codigo' => '6202',
                'ciiu_descripcion' => 'Desarrollo',
                'clase_riesgo_num' => 1,
                'clase_riesgo_desc' => 'Terciario',
                'sector_economico' => 'Servicios',
                'fuente_origen' => 'socrata',
                'enriquecido_at' => '2026-10-03T12:00:00Z',
                'enriquecimiento_hash' => str_repeat('b', 64),
            ], 200),
        ]);

        $client = $this->makeClient();
        $r = $client->consultarEnriquecida('ACME');

        $this->assertSame('900123456-7', $r['nit']);
        $this->assertSame('6202', $r['ciiu_codigo']);
        $this->assertSame(str_repeat('b', 64), $r['enriquecimiento_hash']);

        Http::assertSent(function (Request $req) {
            return $req->url() === 'http://mcp.local:8000/tools/consultar_empresa'
                && $req->method() === 'POST'
                && $req->data() === ['razon_social_o_nit' => 'ACME'];
        });
    }

    #[Test]
    public function consultar_enriquecida_throws_on_4xx(): void
    {
        Http::fake([
            '*consultar_empresa*' => Http::response('Not Found', 404),
        ]);

        $client = $this->makeClient();

        $this->expectException(McpServerUnavailable::class);
        $this->expectExceptionMessageMatches('/404/');

        $client->consultarEnriquecida('NOEXISTE');
    }

    #[Test]
    public function consultar_enriquecida_throws_on_5xx(): void
    {
        Http::fake([
            '*consultar_empresa*' => Http::response('Boom', 500),
        ]);

        $client = $this->makeClient();

        $this->expectException(McpServerUnavailable::class);

        $client->consultarEnriquecida('SOME');
    }

    #[Test]
    public function consultar_enriquecida_throws_on_connection_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        $client = $this->makeClient();

        $this->expectException(McpServerUnavailable::class);

        $client->consultarEnriquecida('SOME');
    }

    // ── ensureServerAvailable (PR3 behavior, retested for safety) ───────

    #[Test]
    public function ensure_server_available_throws_when_url_is_null(): void
    {
        $this->expectException(McpServerUnavailable::class);

        $c = new McpEmpresaClientHttp($this->app->make(Factory::class), null, 10, 3);
        $c->ensureServerAvailable();
    }

    #[Test]
    public function ensure_server_available_throws_when_url_is_empty(): void
    {
        $this->expectException(McpServerUnavailable::class);

        $c = new McpEmpresaClientHttp($this->app->make(Factory::class), '', 10, 3);
        $c->ensureServerAvailable();
    }

    #[Test]
    public function ensure_server_available_throws_on_5xx_response(): void
    {
        Http::fake([
            '*mcp.local*' => Http::response('Boom', 500),
        ]);

        $c = $this->makeClient();

        $this->expectException(McpServerUnavailable::class);

        $c->ensureServerAvailable();
    }

    #[Test]
    public function ensure_server_available_succeeds_on_2xx_response(): void
    {
        Http::fake([
            '*mcp.local*' => Http::response(['ok' => true], 200),
        ]);

        $c = $this->makeClient();

        // No exception expected.
        $c->ensureServerAvailable();

        $this->assertTrue(true);
    }

    // ── timeout guard ────────────────────────────────────────────────────

    #[Test]
    public function constructor_accepts_timeout_value(): void
    {
        $c = new McpEmpresaClientHttp($this->app->make(Factory::class), 'http://mcp.local', 7, 2);
        $reflection = new \ReflectionClass($c);
        $prop = $reflection->getProperty('timeout');
        $prop->setAccessible(true);

        $this->assertSame(7, $prop->getValue($c));
    }
}