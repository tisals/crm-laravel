<?php

namespace App\Infrastructure\Webhook;

use App\Domain\Events\EntidadChanged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Commit 7 — Maps `EntidadChanged` domain events to the Mercurio CQRS
 * mirror webhook (`entidades.snapshot.sync`).
 *
 * Mirrors `PersonasSnapshotEmitter` (PR-J). Key differences:
 *
 *  1. The event carries a UUIDv4 `event_id` so Mercurio can dedupe
 *     replays. The persona event relies on `(persona_id, occurred_at)`
 *     for idempotency; the entidad event adds `event_id` to be safe
 *     across the higher write volume pivot mutations produce.
 *
 *  2. `is_active` is computed by the snapshot builder from the existence
 *     of at least one `entidad_relacion` row with `effective_to IS NULL`
 *     — the legacy `entidad.estado` column was dropped in Commit 5.5 and
 *     the canonical state now lives on the pivot. The listener passes
 *     this flag to Mercurio so the mirror table can update its index
 *     without re-querying crm-laravel.
 *
 * The listener honours the same semantics as `PersonasSnapshotEmitter`:
 *  - kill-switch via `webhook.entidades_snapshot.enabled`
 *  - pushes a `DispatchOutboundWebhookJob` onto the `webhooks` queue
 *  - `flat=true` because the listener owns the wire envelope
 *  - catches ALL throwables (R-3) — a misconfigured webhook path can
 *    NEVER break the originating REST write
 */
class EntidadesSnapshotEmitter
{
    public function handle(EntidadChanged $event): void
    {
        try {
            if (! $this->isEnabled()) {
                Log::info('entidades_snapshot.skipped', [
                    'entidad_id' => $event->entidad_id,
                    'action' => $event->action,
                    'reason' => 'disabled',
                ]);

                return;
            }

            // The listener owns the wire envelope per spec REQ-PSWH-002
            // (mirrored from personas.snapshot.sync to entidades.snapshot.sync).
            // `flat=true` tells the job to skip CrmWebhookSender's automatic
            // wrapping so this shape arrives at the receiver intact.
            $payload = [
                'event' => 'entidades.snapshot.sync',
                'timestamp' => $event->occurred_at,
                'data' => [
                    'action' => $event->action,
                    'entidad_id' => $event->entidad_id,
                    'event_id' => $event->event_id,
                    'snapshot' => $event->snapshot,
                    'occurred_at' => $event->occurred_at,
                ],
            ];

            DispatchOutboundWebhookJob::dispatch(
                'entidades.snapshot.sync',
                $payload,
                'entidades_snapshot',
                true,
            );
        } catch (\Throwable $e) {
            // R-3: never let a webhook hiccup break the originating write.
            Log::error('entidades_snapshot.listener_failed', [
                'entidad_id' => $event->entidad_id,
                'action' => $event->action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Kill-switch. Defaults to TRUE so an unconfigured environment still
     * emits — operators flip `EMIT_ENTIDADES_SNAPSHOT_WEBHOOK=false` in
     * `.env` for an emergency pause. Mirrors the personas kill-switch.
     */
    private function isEnabled(): bool
    {
        return (bool) config('webhook.entidades_snapshot.enabled', true);
    }
}