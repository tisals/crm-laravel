<?php

namespace App\Infrastructure\Webhook;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Centralised HMAC-SHA256 signed webhook transport.
 *
 * Two entry points:
 *  - `send($event, $data, $configPrefix)`: wraps the inner data into the
 *    `{ event, timestamp, data }` envelope, signs the whole body, and POSTs
 *    it to `config("webhook.{$configPrefix}.url")`. Returns the underlying
 *    HTTP response (or null on transport failure) so callers like
 *    `DispatchOutboundWebhookJob` can decide whether to retry on non-2xx.
 *  - `sendRaw($payload, $configPrefix)`: signs and POSTs the payload
 *    AS-IS. Used by listeners that already built the wire envelope
 *    themselves (PR-J's `PersonasSnapshotEmitter`). Decouples envelope
 *    shape from transport.
 *
 * The secret lookup uses `config("webhook.{$configPrefix}.secret")`. For
 * the `personas_snapshot` prefix the secret falls back to
 * `webhook.outbound.secret` when the dedicated env var is unset (REQ-PSWH-004).
 *
 * Transport-level failures (timeout, connection refused) are caught and
 * logged; the method returns null in that case. Non-2xx HTTP responses
 * (e.g. Mercury returning 500) are NOT swallowed — the caller sees the
 * response and can decide to retry. This is what makes REQ-PSWH-003's
 * 3-tries-with-backoff possible at the queue layer.
 */
class CrmWebhookSender
{
    /**
     * Wrap `$data` in the standard envelope, sign, and POST.
     *
     * Returns the underlying HTTP response, or null on transport-level
     * failure (timeout, DNS error, connection refused).
     *
     * @param  string  $configPrefix  Config section under `webhook.*`
     */
    public function send(string $event, array $data, string $configPrefix = 'outbound'): ?Response
    {
        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];

        return $this->sendRaw($payload, $configPrefix, $event);
    }

    /**
     * Sign and POST a pre-built payload as-is, without wrapping.
     *
     * Returns the underlying HTTP response, or null on transport-level
     * failure. Non-2xx HTTP responses are returned (not nulled) so the
     * caller can decide to retry.
     *
     * @param  string  $configPrefix  Config section under `webhook.*`
     * @param  string|null  $eventForLog  Optional event name to log on failure
     */
    public function sendRaw(array $payload, string $configPrefix = 'outbound', ?string $eventForLog = null): ?Response
    {
        $body = json_encode($payload);
        $secret = config("webhook.{$configPrefix}.secret");

        // REQ-PSWH-004: the personas_snapshot and entidades_snapshot secrets
        // MAY fall back to `webhook.outbound.secret` when the dedicated env
        // var is unset. The fallback is scoped to those two CQRS-mirror
        // prefixes so other webhook sections (n8n_pipeline, etc.) are
        // unaffected — those have their own secrets and a missing one is a
        // real misconfiguration.
        if ($secret === null && in_array($configPrefix, ['personas_snapshot', 'entidades_snapshot'], true)) {
            $secret = config('webhook.outbound.secret');
        }

        $signature = 'sha256='.hash_hmac('sha256', (string) $body, (string) $secret);
        $url = config("webhook.{$configPrefix}.url");

        try {
            return Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-CRM-Signature' => $signature,
            ])->timeout(10)->post($url, $payload);
        } catch (\Exception $e) {
            // Transport-level failure (timeout, DNS, connection refused).
            // Non-2xx responses are NOT exceptions — they are returned
            // normally so the caller can decide to retry.
            Log::error("Webhook dispatch failed: {$e->getMessage()}", [
                'event' => $eventForLog ?? ($payload['event'] ?? 'unknown'),
                'url' => $url,
            ]);

            return null;
        }
    }
}
