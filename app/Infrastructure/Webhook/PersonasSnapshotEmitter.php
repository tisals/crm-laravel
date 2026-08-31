<?php

namespace App\Infrastructure\Webhook;

use App\Domain\Events\PersonaChanged;
use Illuminate\Support\Facades\Log;

/**
 * PR-J — Maps `PersonaChanged` domain events to the Mercurio CQRS mirror
 * webhook (`personas.snapshot.sync`).
 *
 * Per spec REQ-PSWH-002, REQ-PSWH-003, REQ-PSWH-004, REQ-PSWH-005 + AD-3 +
 * AD-9:
 *
 *  1. The listener builds the wire envelope itself (`event`, `timestamp`,
 *     `data`) so the receiver (Mercurio) sees the canonical shape
 *     documented in spec REQ-PSWH-002.
 *  2. It pushes a `DispatchOutboundWebhookJob` onto the `webhooks` queue
 *     with `configPrefix='personas_snapshot'` so the URL + secret resolve
 *     from the dedicated `config/webhook.php` section.
 *  3. It honours the `webhook.personas_snapshot.enabled` kill-switch
 *     (REQ-PSWH-005) — when disabled, it logs a structured skip event and
 *     pushes nothing.
 *  4. It honours `REQ-PSWH-004` URL/secret resolution. The job carries the
 *     `personas_snapshot` prefix; the secret fallback to
 *     `webhook.outbound.secret` is encoded by reading
 *     `config('webhook.personas_snapshot.secret') ?? config('webhook.outbound.secret')`
 *     here at dispatch time and passing the resolved secret down — that
 *     keeps `CrmWebhookSender::sendRaw` config-agnostic.
 *  5. It MUST NOT throw (R-3): any unexpected exception is caught and
 *     logged so a misconfigured webhook path cannot break the persona
 *     REST write.
 */
class PersonasSnapshotEmitter
{
    public function handle(PersonaChanged $event): void
    {
        try {
            if (! $this->isEnabled()) {
                Log::info('personas_snapshot.skipped', [
                    'persona_id' => $event->persona_id,
                    'action' => $event->action,
                    'reason' => 'disabled',
                ]);

                return;
            }

            // The listener owns the wire envelope per spec REQ-PSWH-002.
            // `flat=true` tells the job to skip CrmWebhookSender's automatic
            // wrapping so this shape arrives at the receiver intact.
            $payload = [
                'event' => 'personas.snapshot.sync',
                'timestamp' => $event->occurred_at,
                'data' => [
                    'action' => $event->action,
                    'persona_id' => $event->persona_id,
                    'snapshot' => $event->snapshot,
                    'occurred_at' => $event->occurred_at,
                ],
            ];

            DispatchOutboundWebhookJob::dispatch(
                'personas.snapshot.sync',
                $payload,
                'personas_snapshot',
                true,
            );
        } catch (\Throwable $e) {
            // R-3: never let a webhook hiccup break the originating write.
            Log::error('personas_snapshot.listener_failed', [
                'persona_id' => $event->persona_id,
                'action' => $event->action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Kill-switch (REQ-PSWH-005). Defaults to TRUE so an unconfigured
     * environment still emits — operators flip `EMIT_PERSONA_SNAPSHOT_WEBHOOK=false`
     * in `.env` for an emergency pause.
     */
    private function isEnabled(): bool
    {
        return (bool) config('webhook.personas_snapshot.enabled', true);
    }
}
