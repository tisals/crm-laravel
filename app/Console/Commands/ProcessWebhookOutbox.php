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
            if (empty($url)) {
                // Local dev: log and skip rather than 5xx.
                Log::debug('ProcessWebhookOutbox: MERCURIO_WEBHOOK_URL not set, skipping row', [
                    'id' => $row->id,
                    'event_type' => $row->event_type,
                ]);
                continue;
            }

            $payload = json_decode($row->payload_json, true);
            $body = json_encode([
                'event' => $row->event_type,
                'timestamp' => $row->created_at,
                'data' => $payload,
            ]);
            $secret = config('webhook.outbound.secret', env('JANUS_CRM_WEBHOOK_SECRET', ''));
            $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-CRM-Signature' => $signature,
                ])->timeout(10)->post($url, json_decode($body, true));

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
