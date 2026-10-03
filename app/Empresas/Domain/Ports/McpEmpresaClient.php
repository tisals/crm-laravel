<?php

namespace App\Empresas\Domain\Ports;

use App\Empresas\Domain\Entities\EmpresaCandidato;
use App\Empresas\Domain\Entities\EmpresaEnriquecida;

/**
 * PR3 of `complementar-entidad` — entity-empresa-enrichment D-port.
 *
 * Contract for talking to the Python FastMCP server (PR2). The
 * application layer depends ONLY on this interface; the concrete
 * adapters live in `Infrastructure/Mcp/`.
 *
 * Implementations:
 *   - `McpEmpresaClientHttp` — production adapter (HTTP/SSE → :8000)
 *   - `McpEmpresaClientRestFake` — test-only adapter with in-memory state
 *
 * Both methods are NOT responsible for Habeas filtering, Decreto lookup,
 * or persistence — the application service orchestrates those.
 */
interface McpEmpresaClient
{
    /**
     * First-step MCP call: look up homonymia candidates by domain.
     *
     * Returns the raw payload as a list of arrays (NOT EmpresaCandidato
     * objects) — the application layer is responsible for hydrating and
     * validating the shape via `EmpresaCandidato::fromArray()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarPorDominio(string $dominio): array;

    /**
     * Second-step MCP call: fetch the canonical enrichment record for a
     * single entity identified by razon_social OR NIT (whichever the
     * caller has at hand; the MCP server disambiguates).
     *
     * Returns the raw payload as a single array; the application layer
     * hydrates it via `EmpresaEnriquecida::fromArray()`.
     *
     * @return array<string, mixed>
     */
    public function consultarEnriquecida(string $razon_social_o_nit): array;
}