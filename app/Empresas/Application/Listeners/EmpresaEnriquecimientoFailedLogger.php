<?php

namespace App\Empresas\Application\Listeners;

use App\Empresas\Domain\Events\EmpresaEnriquecimientoFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PR4 of `complementar-entidad` — failure-path observability listener.
 *
 * Logs every terminal enrichment failure at WARNING level so security
 * dashboards can alert on `empresa.enriquecimiento_failed` spikes. The
 * log payload carries the failure reason + attempt count so on-call
 * can triage without re-running the job.
 *
 * Mirrors `EmpresaEnriquecidaLogger` (info-level, success path).
 *
 * @see design.md §11 (event contract)
 */
final class EmpresaEnriquecimientoFailedLogger
{
    private const LOG_MESSAGE = 'empresa.enriquecimiento_failed';

    public function handle(EmpresaEnriquecimientoFailed $event): void
    {
        $payload = [
            'entidad_id' => $event->entidad_id,
            'motivo' => $event->motivo,
            'attempts' => $event->attempts,
            'event_id' => $event->event_id,
        ];

        try {
            if (Log::getFacadeRoot() !== null) {
                try {
                    Log::channel('empresas')->warning(self::LOG_MESSAGE, $payload);

                    return;
                } catch (Throwable) {
                    // Channel not configured — fall through.
                }
            }
            Log::warning(self::LOG_MESSAGE, $payload);
        } catch (Throwable) {
            // Fail-soft — observability never blocks the pipeline.
        }
    }
}