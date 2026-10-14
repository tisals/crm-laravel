<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Process webhook outbox: dispatch pending rows to mercurio.
 *
 * Picks rows where status = 'pending' AND (next_retry_at IS NULL OR
 * next_retry_at <= NOW()), ordered by id ASC, limit 100 per tick.
 *
 * On 2xx: DELETE the row.
 * On error/non-2xx: increment attempts, set next_retry_at with
 * exponential backoff (1m, 5m, 25m for attempts 1, 2, 3).
 * After 3 failed attempts: set status = 'failed'.
 *
 * The target URL is read from env MERCURIO_WEBHOOK_URL. If the env
 * is empty (e.g. local dev), the row is logged and skipped so the
 * command does not 5xx in environments without mercurio configured.
 */
class ProcessWebhookOutbox extends Command
{
    protected $signature = 'app:process-webhook-outbox';

    protected $description = 'Process pending rows in the webhook outbox';

    public function handle(): int
    {
        $url = env('MERCURIO_WEBHOOK_URL');

        $rows = DB::table('webhook_outbox')
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($rows->isEmpty()) {
            $this->line('No pending outbox rows.');
            return Command::SUCCESS;
        }

        $this->info("Processing {$rows->count()} outbox row(s).");

        foreach ($rows as $row) {
            // Skip event types that have no consumer in mercurio yet (e.g.
            // `app.upserted` — mercurio has /user-updated but no /app-upserted
            // endpoint). Without this filter, every seed run floods the
            // logs with 404s and burns attempts on events that will never
            // succeed. If/when mercurio adds an /app-upserted consumer,
            // remove this filter.
            if (in_array($row->event_type, ['app.upserted', 'app.deleted'], true)) {
                $this->line("Row {$row->id}: skipping {$row->event_type} (no consumer in mercurio).");
                DB::table('webhook_outbox')->where('id', $row->id)->delete();
                continue;
            }
            if (empty($url)) {
                // Local dev: log and skip rather than 5xx.
                Log::debug('ProcessWebhookOutbox: MERCURIO_WEBHOOK_URL not set, skipping row', [
                    'id' => $row->id,
                    'event_type' => $row->event_type,
                ]);
                continue;
            }

            $payload = json_decode($row->payload_json, true);
            // Spread the payload at top-level (NOT nested under `data`).
            // Mercurio's webhook handlers look for fields like `user_id`,
            // `event`, etc. at the body root, not inside a `data` envelope.
            $body = json_encode(array_merge(
                ['event' => $row->event_type, 'timestamp' => $row->created_at],
                $payload ?: []
            ));
            // Prefer the env var over the config file default. The original
            // code had this reversed: `config('webhook.outbound.secret', env(...))`
            // which meant the placeholder `'your-webhook-secret-key'` from
            // config/webhook.php always won, causing HMAC mismatch with
            // mercurio (which uses `CRM_WEBHOOK_SECRET=111acdf...`).
            $secret = env('JANUS_CRM_WEBHOOK_SECRET', config('webhook.outbound.secret', ''));
            $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

            try {
                // Send the EXACT bytes that were signed. Passing an array to
                // `Http::post()` causes Laravel to re-serialize it, which can
                // change key order, whitespace, or escape characters — and
                // those bytes would no longer match the HMAC we computed.
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-CRM-Signature' => $signature,
                ])->timeout(10)->withBody($body, 'application/json')->post($url);

                if ($response->successful()) {
                    DB::table('webhook_outbox')->where('id', $row->id)->delete();
                    $this->line("Row {$row->id}: delivered successfully.");
                } else {
                    $this->handleFailure($row, "HTTP {$response->status()}: {$response->body()}");
                }
            } catch (\Exception $e) {
                $this->handleFailure($row, $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    private function handleFailure(object $row, string $error): void
    {
        $attempts = $row->attempts + 1;

        if ($attempts >= 3) {
            DB::table('webhook_outbox')
                ->where('id', $row->id)
                ->update([
                    'attempts' => $attempts,
                    'status' => 'failed',
                    'last_error' => $error,
                    'updated_at' => now(),
                ]);
            $this->warn("Row {$row->id}: marked FAILED after 3 attempts.");
        } else {
            // Exponential backoff: 1m, 5m, 25m
            $delaySeconds = 60 * pow(5, $attempts - 1);
            $nextRetry = now()->addSeconds($delaySeconds);

            DB::table('webhook_outbox')
                ->where('id', $row->id)
                ->update([
                    'attempts' => $attempts,
                    'status' => 'pending',
                    'next_retry_at' => $nextRetry,
                    'last_error' => $error,
                    'updated_at' => now(),
                ]);
            $this->warn("Row {$row->id}: attempt {$attempts} failed, next retry at {$nextRetry}.");
        }

        Log::error('ProcessWebhookOutbox: delivery failed', [
            'id' => $row->id,
            'event_type' => $row->event_type,
            'attempts' => $attempts,
            'error' => $error,
        ]);
    }
}
