<?php

namespace App\Empresas\Application\Listeners;

use App\Empresas\Domain\Events\EmpresaEnriquecida;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PR4 of `complementar-entidad` — observability listener.
 *
 * Logs every successful enrichment so the SRE dashboard can chart
 * `empresa.enriquecida` events. The log line carries the full event
 * payload (entidad_id, fuente_origen, enriquecido_at,
 * enriquecimiento_hash, event_id) which lets operators trace the
 * enrichment pipeline end-to-end.
 *
 * The listener swallows all logging errors so an outage of the log
 * store never breaks the enrichment pipeline (fail-soft contract).
 *
 * The 'empresas' channel is configured in `config/logging.php` to
 * route these entries to a dedicated file. Tests fall back to the
 * default channel via `Log::info()` so spies can capture the call.
 *
 * @see design.md §11 (event contract)
 */
final class EmpresaEnriquecidaLogger
{
    private const LOG_MESSAGE = 'empresa.enriquecida';

    public function handle(EmpresaEnriquecida $event): void
    {
        $payload = [
            'entidad_id' => $event->entidad_id,
            'fuente_origen' => $event->fuente_origen,
            'enriquecido_at' => $event->enriquecido_at,
            'enriquecimiento_hash' => $event->enriquecimiento_hash,
            'event_id' => $event->event_id,
        ];

        try {
            // Use the channel-routed logger when the channel is
            // configured (production); fall back to the default
            // channel so tests can capture the call.
            if (Log::getFacadeRoot() !== null) {
                try {
                    Log::channel('empresas')->info(self::LOG_MESSAGE, $payload);

                    return;
                } catch (Throwable) {
                    // Channel not configured — fall through.
                }
            }
            Log::info(self::LOG_MESSAGE, $payload);
        } catch (Throwable) {
            // Fail-soft — observability never blocks the pipeline.
        }
    }
}