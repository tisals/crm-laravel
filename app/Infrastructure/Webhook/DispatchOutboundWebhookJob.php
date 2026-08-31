<?php

namespace App\Infrastructure\Webhook;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Generic queued webhook dispatch job.
 *
 * PR-J extends the original two-arg constructor with two optional knobs:
 *
 *  - `$configPrefix` (string, default 'outbound'): the `config/webhook.php`
 *    section to read URL + secret from. The existing listeners
 *    (ContactUpdated, OrganizationCreated, PaymentCompleted) all default to
 *    'outbound' so they keep their current behavior with no caller changes.
 *    The personas_snapshot flow passes 'personas_snapshot' so its URL +
 *    secret can live in a dedicated config section.
 *
 *  - `$flat` (bool, default false): when true, the job skips the
 *    `{ event, timestamp, data }` envelope inside `CrmWebhookSender::send`
 *    and POSTs the data payload as-is. The personas_snapshot listener
 *    builds the wire envelope itself (matching spec REQ-PSWH-002 step 1)
 *    so the job acts purely as a transport. The flag exists for forward
 *    compat; current existing callers leave it false.
 *
 * `tries=3` + `backoff()=>[60,120,180]` matches the existing retry budget
 * for the Mercurio flow (ADD-AUTH-001). After 3 failures the job lands
 * in the `failed_jobs` table for manual replay per REQ-PSWH-003.
 *
 * **Retry semantics** (REQ-PSWH-003, R-3):
 *   - HTTP 5xx, 4xx, or transport failure → throws RuntimeException → queue
 *     retries with the documented backoff. After `tries` attempts the job
 *     is moved to `failed_jobs`.
 *   - HTTP 2xx → returns silently, no retry.
 *   - Throws are caught ONLY at the listener boundary (PersonasSnapshotEmitter
 *     etc.) — listeners MUST NOT swallow here, otherwise the queue retry
 *     machinery never sees the failure.
 */
class DispatchOutboundWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $event,
        public array $data,
        public string $configPrefix = 'outbound',
        public bool $flat = false,
    ) {
        $this->queue = 'webhooks';
    }

    public function backoff(): array
    {
        return [60, 120, 180];
    }

    public function handle(CrmWebhookSender $sender): void
    {
        $response = $this->flat
            ? $sender->sendRaw($this->data, $this->configPrefix)
            : $sender->send($this->event, $this->data, $this->configPrefix);

        // Transport-level failure (timeout, DNS) → sender returned null →
        // we surface it as a RuntimeException so the queue worker retries.
        if ($response === null) {
            throw new RuntimeException("Webhook transport failed for event {$this->event}");
        }

        // Non-2xx → throw so the queue retries with backoff. REQ-PSWH-003:
        // after 3 tries, the job lands in `failed_jobs`.
        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Webhook receiver returned %d for event %s',
                $response->status(),
                $this->event,
            ));
        }
    }
}
