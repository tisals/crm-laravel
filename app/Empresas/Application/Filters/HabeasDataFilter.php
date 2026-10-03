<?php

namespace App\Empresas\Application\Filters;

use App\Empresas\Domain\Entities\EmpresaEnriquecida;

/**
 * PR3 of `complementar-entidad` — habeas-data-filter D4 (placeholder impl).
 *
 * Ley 1581/2012 (Habeas Data, Colombia) compliance for natural-person
 * records surfaced by the enrichment flow. When the entity is a
 * `Natural` persona, the NIT (== cédula) and PII SHALL be masked per
 * the configured mode.
 *
 * PR3 ships ONLY the `mask` mode as a placeholder; `exclude` and `raw`
 * modes return null or unchanged, respectively. PR4 extends `exclude`
 * to actually log the exclusion and short-circuit the persistence call.
 *
 * The filter does NOT decide persona classification — that's the
 * responsibility of the application service which already knows the
 * `Entidad::tipo_persona` value. This filter only operates on the
 * payload itself.
 *
 * @see ${SPEC}/specs/habeas-data-filter/spec.md
 * @see design.md §9
 */
final class HabeasDataFilter
{
    public const MODE_MASK = 'mask';
    public const MODE_EXCLUDE = 'exclude';
    public const MODE_RAW = 'raw';

    public function __construct(
        private readonly string $defaultMode = self::MODE_MASK,
    ) {}

    /**
     * Apply the configured mode to the enrichment payload.
     *
     * @return EmpresaEnriquecida|null The filtered payload, or `null`
     *                                 when `exclude` mode requests the
     *                                 record to be dropped entirely.
     */
    public function apply(EmpresaEnriquecida $data, string $tipoPersona = 'Natural', ?string $mode = null): ?EmpresaEnriquecida
    {
        // Juridica records bypass the filter regardless of mode (the
        // service has already classified the entity upstream).
        if ($tipoPersona !== 'Natural') {
            return $data;
        }

        // Mode resolution order:
        //   1. explicit $mode argument
        //   2. `empresas.habeas_data_mode` config (re-read each call so
        //      test-time config overrides take effect immediately)
        //   3. instance default (constructor-injected)
        $effectiveMode = $mode
            ?? (function_exists('config') ? config('empresas.habeas_data_mode') : null)
            ?? $this->defaultMode;

        return match ($effectiveMode) {
            self::MODE_EXCLUDE => null,
            self::MODE_RAW => $data,
            self::MODE_MASK => $this->mask($data),
            default => $this->mask($data),
        };
    }

    /**
     * Mask NIT (`MASKED-CC-{last4}`) and keep all other fields verbatim.
     * PR3 ships only this NIT-mask variant; PII field masking (email,
     * telefono, direccion) is not yet part of the persisted payload
     * schema, so there is nothing further to scrub.
     */
    private function mask(EmpresaEnriquecida $data): EmpresaEnriquecida
    {
        $last4 = substr(preg_replace('/\D+/', '', $data->nit) ?? '', -4);
        $last4 = $last4 !== '' ? $last4 : '0000';
        $maskedNit = 'MASKED-CC-' . $last4;

        // EmpresaEnriquecida is readonly; we cannot mutate it. Rebuild
        // a new instance with the masked NIT and identical other fields.
        return new EmpresaEnriquecida(
            razon_social: $data->razon_social,
            nit: $maskedNit,
            numero_empleados: $data->numero_empleados,
            ciiu_codigo: $data->ciiu_codigo,
            ciiu_descripcion: $data->ciiu_descripcion,
            clase_riesgo_num: $data->clase_riesgo_num,
            clase_riesgo_desc: $data->clase_riesgo_desc,
            sector_economico: $data->sector_economico,
            fuente_origen: $data->fuente_origen,
            enriquecido_at: $data->enriquecido_at,
            enriquecimiento_hash: $data->enriquecimiento_hash,
        );
    }
}