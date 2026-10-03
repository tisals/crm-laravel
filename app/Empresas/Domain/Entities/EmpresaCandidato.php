<?php

namespace App\Empresas\Domain\Entities;

/**
 * PR3 of `complementar-entidad` — entity-empresa-enrichment D1 + D2.
 *
 * Pure-PHP value object for one homonymia candidate returned by the
 * Python FastMCP server's `buscarPorDominio` tool.
 *
 * The DTO is intentionally framework-free — it lives in `Domain/Entities`
 * and may NOT import Eloquent, Sanctum, or any other Laravel package.
 * It is `final` and immutable (`readonly`) so it can be safely threaded
 * across the queue boundary without defensive copies.
 *
 * Per design §3: a candidato carries
 *   - razon_social (required, trimmed at construction)
 *   - nit          (required, trimmed at construction)
 *   - camara_comercio (informational; may be empty)
 *   - ciudad       (informational; may be empty)
 *   - fuente_origen (constrained to socrata|rues|manual)
 */
final class EmpresaCandidato
{
    private const VALID_FUENTES = ['socrata', 'rues', 'manual'];

    public function __construct(
        public readonly string $razon_social,
        public readonly string $nit,
        public readonly string $camara_comercio,
        public readonly string $ciudad,
        public readonly string $fuente_origen,
    ) {}

    /**
     * Build a candidato from the heterogeneous payload shape the MCP
     * server emits. Trims string whitespace, validates the constrained
     * `fuente_origen` value, and rejects empty required fields.
     *
     * @throws \InvalidArgumentException when a required field is empty
     *         or `fuente_origen` is not in the canonical set.
     */
    public static function fromArray(array $data): self
    {
        // razon_social preserves internal whitespace (some legal names
        // legitimately contain leading/trailing spaces); only NIT and
        // the metadata fields are trimmed.
        $razon_social = (string) ($data['razon_social'] ?? '');
        $nit = trim((string) ($data['nit'] ?? ''));
        $camara_comercio = trim((string) ($data['camara_comercio'] ?? ''));
        $ciudad = trim((string) ($data['ciudad'] ?? ''));
        $fuente_origen = trim((string) ($data['fuente_origen'] ?? ''));

        if (trim($razon_social) === '') {
            throw new \InvalidArgumentException('razon_social is required for EmpresaCandidato.');
        }

        if ($nit === '') {
            throw new \InvalidArgumentException('nit is required for EmpresaCandidato.');
        }

        if (!in_array($fuente_origen, self::VALID_FUENTES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'fuente_origen must be one of [%s]; got "%s".',
                implode(', ', self::VALID_FUENTES),
                $fuente_origen
            ));
        }

        return new self($razon_social, $nit, $camara_comercio, $ciudad, $fuente_origen);
    }
}