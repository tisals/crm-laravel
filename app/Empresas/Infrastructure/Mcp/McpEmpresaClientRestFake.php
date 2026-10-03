<?php

namespace App\Empresas\Infrastructure\Mcp;

use App\Empresas\Domain\Ports\McpEmpresaClient;
use Throwable;

/**
 * PR3 of `complementar-entidad` — test-only adapter for the MCP port.
 *
 * Implements `McpEmpresaClient` with in-memory state so PHPUnit
 * Feature tests can drive `EnriquecerEmpresaService` without
 * touching the real Python FastMCP server (which runs in a
 * separate Docker container and is opt-in via `@group mcp-integration`).
 *
 * Bound in `EmpresasServiceProvider` ONLY when `app()->environment('testing')`
 * — production traffic always hits `McpEmpresaClientHttp`.
 */
class McpEmpresaClientRestFake implements McpEmpresaClient
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $candidatosByDominio = [];

    /** @var array<string, array<string, mixed>> */
    private array $enriquecidaByKey = [];

    private ?Throwable $exceptionToThrow = null;

    private int $delayMs = 0;

    public int $buscarCalls = 0;

    public int $consultarCalls = 0;

    /**
     * Pre-populate the homonymia candidate list returned by the next
     * `buscarPorDominio()` call for the given domain.
     *
     * @param array<int, array<string, mixed>> $candidatos
     */
    public function setCandidatos(string $dominio, array $candidatos): void
    {
        $this->candidatosByDominio[strtolower(trim($dominio))] = $candidatos;
    }

    /**
     * Pre-populate the canonical enrichment payload returned by the
     * next `consultarEnriquecida()` call for the given razon_social or NIT.
     */
    public function setEnriquecida(string $key, array $data): void
    {
        $this->enriquecidaByKey[$key] = $data;
    }

    /**
     * Force the next call (either method) to throw the supplied
     * exception. Cleared after one throw so the test does not have
     * to reset it between scenarios.
     */
    public function setException(Throwable $e): void
    {
        $this->exceptionToThrow = $e;
    }

    public function setDelayMs(int $ms): void
    {
        $this->delayMs = $ms;
    }

    public function buscarPorDominio(string $dominio): array
    {
        $this->buscarCalls++;
        $this->maybeThrow();
        $this->maybeDelay();

        $key = strtolower(trim($dominio));

        return $this->candidatosByDominio[$key] ?? [];
    }

    public function consultarEnriquecida(string $razon_social_o_nit): array
    {
        $this->consultarCalls++;
        $this->maybeThrow();
        $this->maybeDelay();

        $key = trim($razon_social_o_nit);

        if (! isset($this->enriquecidaByKey[$key])) {
            throw new \RuntimeException("RestFake: no fixture for '{$key}'.");
        }

        return $this->enriquecidaByKey[$key];
    }

    private function maybeThrow(): void
    {
        if ($this->exceptionToThrow !== null) {
            $e = $this->exceptionToThrow;
            $this->exceptionToThrow = null;
            throw $e;
        }
    }

    private function maybeDelay(): void
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
    }
}