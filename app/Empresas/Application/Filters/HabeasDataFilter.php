<?php

namespace App\Empresas\Application\Filters;

use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PR4 of `complementar-entidad` — habeas-data-filter D4 (full impl).
 *
 * Ley 1581/2012 (Habeas Data, Colombia) compliance for natural-person
 * records surfaced by the enrichment flow. When the entity is a
 * `Natural` persona, the NIT (== cédula) and PII SHALL be masked per
 * the configured mode; the filter SHALL be applied before persisting
 * enrichment and SHALL audit-log every application.
 *
 * Modes (per spec `habeas-data-filter/spec.md`):
 *   - `mask`    — NIT → `MASKED-CC-{last4}`; payload otherwise preserved
 *   - `exclude` — return null; caller must skip persistence
 *   - `raw`     — pass the payload through unchanged (operator override)
 *
 * Audit log contract (single emission per `apply()` call):
 *   - channel  : 'empresas'
 *   - level    : info (masked | excluded | passthrough) | warning (bypassed)
 *   - fields   : `entidad_id`, `tipo_persona`, `modo`, `decision`,
 *                `ocurred_at` (ISO 8601), `event_id` (UUIDv4)
 *
 * The constructor accepts the logger optionally — the PR3 service
 * provider does NOT inject one, so production runs without audit logs
 * until PR4 wires the `empresas` channel binding in the provider.
 *
 * @see ${SPEC}/specs/habeas-data-filter/spec.md
 * @see design.md §9
 */
final class HabeasDataFilter
{
    public const MODE_MASK = 'mask';
    public const MODE_EXCLUDE = 'exclude';
    public const MODE_RAW = 'raw';

    private const LOG_CHANNEL = 'empresas';

    public function __construct(
        private readonly string $defaultMode = self::MODE_MASK,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Apply the configured mode to the enrichment payload.
     *
     * @param  string                    $tipoPersona  'Natural' | 'Juridica'
     * @param  string|null               $mode         explicit per-call override
     * @param  int|null                  $entidadId    included in audit log
     * @return EmpresaEnriquecida|null   The filtered payload, or `null`
     *                                  when `exclude` mode requests the
     *                                  record to be dropped entirely.
     */
    public function apply(
        EmpresaEnriquecida $data,
        string $tipoPersona = 'Natural',
        ?string $mode = null,
        ?int $entidadId = null,
    ): ?EmpresaEnriquecida {
        // Juridica records bypass the filter regardless of mode (the
        // service has already classified the entity upstream).
        if ($tipoPersona !== 'Natural') {
            $this->audit('habeas_data.passthrough', 'passthrough', $tipoPersona, $mode, $entidadId, self::MODE_MASK);

            return $data;
        }

        // Mode resolution order:
        //   1. explicit $mode argument
        //   2. `empresas.habeas_data_mode` config (re-read each call so
        //      test-time config overrides take effect immediately)
        //   3. instance default (constructor-injected)
        $effectiveMode = $mode ?? $this->resolveConfiguredMode() ?? $this->defaultMode;

        // Unknown modes fall back to 'mask' and are logged so operators
        // notice typos in their .env file.
        if (!in_array($effectiveMode, [self::MODE_MASK, self::MODE_EXCLUDE, self::MODE_RAW], true)) {
            if ($this->logger !== null) {
                $this->logger->warning('habeas_data.unknown_mode_fallback', [
                    'modo_declared' => $effectiveMode,
                    'modo_applied' => self::MODE_MASK,
                    'entidad_id' => $entidadId,
                ]);
            }
            $effectiveMode = self::MODE_MASK;
        }

        return match ($effectiveMode) {
            self::MODE_EXCLUDE => $this->exclude($tipoPersona, $entidadId, $effectiveMode),
            self::MODE_RAW => $this->raw($data, $tipoPersona, $entidadId, $effectiveMode),
            default => $this->mask($data, $tipoPersona, $entidadId, $effectiveMode),
        };
    }

    /**
     * Mask NIT (`MASKED-CC-{last4}`) and keep all other fields verbatim.
     * PII fields (email/telefono/direccion) are not yet on the
     * `EmpresaEnriquecida` DTO so there is nothing further to scrub.
     */
    private function mask(EmpresaEnriquecida $data, string $tipoPersona, ?int $entidadId, string $effectiveMode): EmpresaEnriquecida
    {
        $last4 = substr(preg_replace('/\D+/', '', $data->nit) ?? '', -4);
        $last4 = $last4 !== '' ? $last4 : '0000';
        $maskedNit = 'MASKED-CC-' . $last4;

        // EmpresaEnriquecida is readonly; we cannot mutate it. Rebuild
        // a new instance with the masked NIT and identical other fields.
        $result = new EmpresaEnriquecida(
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

        $this->audit('habeas_data.applied', 'masked', $tipoPersona, $effectiveMode, $entidadId);

        return $result;
    }

    private function exclude(string $tipoPersona, ?int $entidadId, string $effectiveMode): null
    {
        $this->audit('habeas_data.excluded', 'excluded', $tipoPersona, $effectiveMode, $entidadId);

        return null;
    }

    private function raw(EmpresaEnriquecida $data, string $tipoPersona, ?int $entidadId, string $effectiveMode): EmpresaEnriquecida
    {
        // Operator-only override — emit a WARNING-level audit entry
        // so security/SOX audits can spot accidental raw deployments.
        $this->audit('habeas_data.bypass_warning', 'bypassed', $tipoPersona, $effectiveMode, $entidadId, level: 'warning');

        return $data;
    }

    /**
     * Single emission point for the audit log. Spec mandates exactly
     * one record per `apply()` call carrying:
     *   `entidad_id` · `tipo_persona` · `modo` · `decision` ·
     *   `event_id` (UUIDv4) · `ocurred_at` (ISO 8601)
     */
    private function audit(
        string $message,
        string $decision,
        string $tipoPersona,
        ?string $modo,
        ?int $entidadId,
        string $level = 'info',
    ): void {
        $logger = $this->resolveLogger();
        if ($logger === null) {
            return;
        }

        $payload = [
            'entidad_id' => $entidadId,
            'tipo_persona' => $tipoPersona,
            'modo' => $modo ?? $this->defaultMode,
            'decision' => $decision,
            'event_id' => (string) Str::uuid(),
            'ocurred_at' => Carbon::now()->toIso8601String(),
        ];

        try {
            if ($level === 'warning') {
                $logger->warning($message, $payload);
            } else {
                $logger->info($message, $payload);
            }
        } catch (Throwable) {
            // Never let audit-log failure break the enrichment pipeline.
        }
    }

    private function resolveLogger(): ?LoggerInterface
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        // Fall back to the channel-bound 'empresas' logger so production
        // runs (where the provider does NOT inject a logger) still emit
        // audit entries to the canonical channel.
        try {
            return Log::channel(self::LOG_CHANNEL);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read `empresas.habeas_data_mode` from the running container, but
     * tolerate the case where the container is NOT bootstrapped (pure
     * PHPUnit unit tests). Always returns null when the container is
     * absent so callers can fall back to the instance default.
     */
    private function resolveConfiguredMode(): ?string
    {
        if (!function_exists('config') || !function_exists('app')) {
            return null;
        }

        try {
            $app = app();
            if (!method_exists($app, 'bound') || !$app->bound('config')) {
                return null;
            }

            $value = (string) config('empresas.habeas_data_mode');

            return $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}