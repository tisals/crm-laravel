<?php

namespace App\Empresas\Infrastructure\Mcp;

use App\Empresas\Domain\Ports\McpEmpresaClient;
use App\Empresas\Infrastructure\Mcp\Exceptions\McpServerUnavailable;
use App\Empresas\Infrastructure\Mcp\Exceptions\NotImplementedInScaffoldException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * PR4 of `complementar-entidad` — production HTTP/SSE adapter for the
 * Python FastMCP server. PR3 shipped this class as a placeholder that
 * threw `NotImplementedInScaffoldException` on every MCP call; PR4
 * wires the real FastMCP HTTP transport (the server exposes its tools
 * under `/tools/<name>` when launched with `MCP_TRANSPORT=http`).
 *
 * Wire contract:
 *   - `buscarPorDominio($dominio)`:
 *       POST <url>/tools/buscar_por_dominio  body `{ dominio }`  → array
 *   - `consultarEnriquecida($razon_social_o_nit)`:
 *       POST <url>/tools/consultar_empresa   body `{ razon_social_o_nit }` → array
 *
 * Error handling (per design §7):
 *   - 4xx  → `McpServerUnavailable` (terminal — caller MUST NOT retry)
 *   - 5xx  → `McpServerUnavailable` (delegated to the queued job's
 *            retry/backoff policy; not a programmer error)
 *   - timeout / DNS / connection failure → `McpServerUnavailable`
 *
 * Constructor parameters match the spec (design §3):
 *   - `Http::timeout($config('empresas.mcp_timeout'))` (Http facade)
 *   - `config('empresas.mcp_url')`
 *   - `config('empresas.mcp_timeout')`
 *   - `config('empresas.mcp_max_retries')` (informational; the queued
 *      job owns the retry loop)
 */
class McpEmpresaClientHttp implements McpEmpresaClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $url = null,
        private readonly int $timeout = 10,
        private readonly int $maxRetries = 3,
    ) {}

    /**
     * First-step MCP call: look up homonymia candidates by domain.
     *
     * Returns the raw JSON array the FastMCP server emits; the
     * application layer is responsible for hydrating each element via
     * `EmpresaCandidato::fromArray()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarPorDominio(string $dominio): array
    {
        $response = $this->post('/tools/buscar_por_dominio', ['dominio' => $dominio]);

        $decoded = $response->json();

        // FastMCP returns the result wrapped in `{"result": [...]}` when
        // invoked via the JSON-RPC bridge; the bare-array shape is used
        // by the REST adapter. Accept both for forward-compat.
        if (is_array($decoded) && array_key_exists('result', $decoded) && is_array($decoded['result'])) {
            return $decoded['result'];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Second-step MCP call: fetch the canonical enrichment record for
     * a single entity identified by razon_social OR NIT.
     *
     * Returns the raw JSON payload; the application layer hydrates it
     * via `EmpresaEnriquecida::fromArray()`.
     *
     * @return array<string, mixed>
     */
    public function consultarEnriquecida(string $razon_social_o_nit): array
    {
        $response = $this->post('/tools/consultar_empresa', [
            'razon_social_o_nit' => $razon_social_o_nit,
        ]);

        $decoded = $response->json();

        // Same `result` wrapping as buscarPorDominio.
        if (is_array($decoded) && array_key_exists('result', $decoded) && is_array($decoded['result'])) {
            return $decoded['result'];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Probe the configured URL and throw if it is unreachable.
     *
     * Used by the application's pre-flight check (PR5 will add a
     * health-check command). Returns silently on 2xx, throws on 5xx
     * and connection errors.
     */
    public function ensureServerAvailable(): void
    {
        if ($this->url === null || $this->url === '') {
            throw new McpServerUnavailable(
                'config(empresas.mcp_url) is empty; cannot probe the FastMCP server.'
            );
        }

        try {
            $response = $this->http->timeout($this->timeout)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->url);
        } catch (ConnectionException $e) {
            throw new McpServerUnavailable(
                sprintf('FastMCP server at %s is unreachable: %s', $this->url, $e->getMessage()),
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new McpServerUnavailable(
                sprintf('FastMCP server at %s probe failed: %s', $this->url, $e->getMessage()),
                previous: $e,
            );
        }

        if ($response->status() >= 500) {
            throw new McpServerUnavailable(
                sprintf('FastMCP server at %s returned HTTP %d.', $this->url, $response->status())
            );
        }
    }

    /**
     * Shared POST helper that maps HTTP errors to typed exceptions.
     */
    private function post(string $path, array $body): \Illuminate\Http\Client\Response
    {
        if ($this->url === null || $this->url === '') {
            throw new NotImplementedInScaffoldException(
                'config(empresas.mcp_url) is empty; McpEmpresaClientHttp is unconfigured.'
            );
        }

        try {
            $response = $this->http->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->url, '/') . $path, $body);
        } catch (ConnectionException $e) {
            throw new McpServerUnavailable(
                sprintf('FastMCP server at %s is unreachable: %s', $this->url, $e->getMessage()),
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new McpServerUnavailable(
                sprintf('FastMCP server at %s request failed: %s', $this->url, $e->getMessage()),
                previous: $e,
            );
        }

        $status = $response->status();
        if ($status >= 400) {
            throw new McpServerUnavailable(sprintf(
                'FastMCP server at %s%s returned HTTP %d for %s payload %s.',
                $this->url,
                $path,
                $status,
                $path,
                json_encode($body, JSON_UNESCAPED_UNICODE)
            ));
        }

        return $response;
    }
}