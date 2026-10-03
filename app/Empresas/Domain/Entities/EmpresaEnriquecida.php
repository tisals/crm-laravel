<?php

namespace App\Empresas\Domain\Entities;

/**
 * PR3 of `complementar-entidad` — entity-empresa-enrichment D1.
 *
 * Pure-PHP value object for the final enrichment payload (post-Habeas
 * filter) that the application service persists into the annex table
 * `entidad_enriquecimiento`. Lives in `Domain/Entities` with zero
 * framework dependencies — strictly `final` and `readonly` so it can
 * be passed across the queue boundary safely.
 *
 * Per design §3 / §11 the canonical fields are:
 *   razon_social, nit, numero_empleados, ciiu_codigo, ciiu_descripcion,
 *   clase_riesgo_num (1..5), clase_riesgo_desc, sector_economico,
 *   fuente_origen (socrata|rues|manual), enriquecido_at (ISO 8601),
 *   enriquecimiento_hash (64-char SHA-256 hex).
 */
final class EmpresaEnriquecida
{
    private const VALID_FUENTES = ['socrata', 'rues', 'manual'];
    private const VALID_CLASES = [1, 2, 3, 4, 5];

    public function __construct(
        public readonly string $razon_social,
        public readonly string $nit,
        public readonly int $numero_empleados,
        public readonly string $ciiu_codigo,
        public readonly string $ciiu_descripcion,
        public readonly int $clase_riesgo_num,
        public readonly string $clase_riesgo_desc,
        public readonly string $sector_economico,
        public readonly string $fuente_origen,
        public readonly string $enriquecido_at,
        public readonly string $enriquecimiento_hash,
    ) {}

    /**
     * Build an enrichment payload from the array shape the MCP server
     * returns (or that the cache layer hydrates from a previous run).
     *
     * Validates:
     *   - `fuente_origen` is socrata|rues|manual
     *   - `clase_riesgo_num` is 1..5 (Decreto 768/2022 ARL classes)
     *   - `enriquecimiento_hash` is a 64-char lowercase hex string
     *
     * @throws \InvalidArgumentException on any validation failure.
     */
    public static function fromArray(array $data): self
    {
        $razon_social = trim((string) ($data['razon_social'] ?? ''));
        $nit = trim((string) ($data['nit'] ?? ''));
        $ciiu_codigo = trim((string) ($data['ciiu_codigo'] ?? ''));
        $ciiu_descripcion = trim((string) ($data['ciiu_descripcion'] ?? ''));
        $clase_riesgo_desc = trim((string) ($data['clase_riesgo_desc'] ?? ''));
        $sector_economico = trim((string) ($data['sector_economico'] ?? ''));
        $fuente_origen = trim((string) ($data['fuente_origen'] ?? ''));
        $enriquecido_at = trim((string) ($data['enriquecido_at'] ?? ''));
        $enriquecimiento_hash = trim((string) ($data['enriquecimiento_hash'] ?? ''));
        $numero_empleados = (int) ($data['numero_empleados'] ?? 0);
        $clase_riesgo_num = (int) ($data['clase_riesgo_num'] ?? 0);

        if (!in_array($fuente_origen, self::VALID_FUENTES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'fuente_origen must be one of [%s]; got "%s".',
                implode(', ', self::VALID_FUENTES),
                $fuente_origen
            ));
        }

        if (!in_array($clase_riesgo_num, self::VALID_CLASES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'clase_riesgo_num must be one of [%s]; got %d.',
                implode(', ', self::VALID_CLASES),
                $clase_riesgo_num
            ));
        }

        if (!preg_match('/^[0-9a-f]{64}$/', $enriquecimiento_hash)) {
            throw new \InvalidArgumentException(sprintf(
                'enriquecimiento_hash must be a 64-char lowercase hex string; got length %d.',
                strlen($enriquecimiento_hash)
            ));
        }

        return new self(
            $razon_social,
            $nit,
            $numero_empleados,
            $ciiu_codigo,
            $ciiu_descripcion,
            $clase_riesgo_num,
            $clase_riesgo_desc,
            $sector_economico,
            $fuente_origen,
            $enriquecido_at,
            $enriquecimiento_hash,
        );
    }
}