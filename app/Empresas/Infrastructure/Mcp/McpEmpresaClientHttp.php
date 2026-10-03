<?php

namespace App\Empresas\Infrastructure\Mcp;

/**
 * PR3 of `complementar-entidad` — production adapter (placeholder).
 *
 * Real HTTP/SSE wiring ships in PR4 once the FastMCP server is
 * deployed and reachable from this container. PR3 ships only the
 * class shell with constructor injection so the service container
 * can resolve it without throwing — actual calls throw a
 * `NotImplementedInScaffoldException` so the failure mode is loud
 * and testable.
 *
 * Constructor parameters match the spec (design §3):
 *   - `Http::timeout($config('empresas.mcp_timeout'))` (Http facade)
 *   - `config('empresas.mcp_url')`
 *   - `config('empresas.mcp_timeout')`
 *   - `config('empresas.mcp_max_retries')`
 */
class McpEmpresaClientHttp implements \App\Empresas\Domain\Ports\McpEmpresaClient
{
    public function __construct(
        private readonly \Illuminate\Http\Client\Factory $http,
        private readonly ?string $url = null,
        private readonly int $timeout = 10,
        private readonly int $maxRetries = 3,
    ) {}

    public function buscarPorDominio(string $dominio): array
    {
        throw new \App\Empresas\Infrastructure\Mcp\Exceptions\NotImplementedInScaffoldException(
            'McpEmpresaClientHttp::buscarPorDominio is not implemented in PR3 — wire the real HTTP call in PR4.'
        );
    }

    public function consultarEnriquecida(string $razon_social_o_nit): array
    {
        throw new \App\Empresas\Infrastructure\Mcp\Exceptions\NotImplementedInScaffoldException(
            'McpEmpresaClientHttp::consultarEnriquecida is not implemented in PR3 — wire the real HTTP call in PR4.'
        );
    }

    /**
     * Probe the configured URL and throw if it is unreachable.
     * PR4 will call this from the service when the kill-switch
     * is enabled; PR3 keeps the method so the wire contract is in place.
     */
    public function ensureServerAvailable(): void
    {
        if ($this->url === null || $this->url === '') {
            throw new \App\Empresas\Infrastructure\Mcp\Exceptions\McpServerUnavailable(
                'config(empresas.mcp_url) is empty; cannot probe the FastMCP server.'
            );
        }

        try {
            $response = $this->http->timeout($this->timeout)
                ->withHeaders(['Accept' => 'application/json'])
                ->get($this->url);
        } catch (\Throwable $e) {
            throw new \App\Empresas\Infrastructure\Mcp\Exceptions\McpServerUnavailable(
                sprintf('FastMCP server at %s is unreachable: %s', $this->url, $e->getMessage()),
                previous: $e,
            );
        }

        if ($response->status() >= 500) {
            throw new \App\Empresas\Infrastructure\Mcp\Exceptions\McpServerUnavailable(
                sprintf('FastMCP server at %s returned HTTP %d.', $this->url, $response->status())
            );
        }
    }
}