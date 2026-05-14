<?php

namespace App\Infrastructure\Webhook;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CrmWebhookSender
{
    public function send(string $event, array $data): void
    {
        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];

        $body = json_encode($payload);
        $secret = config('webhook.outbound.secret');
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $url = config('webhook.outbound.url');

        try {
            Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-CRM-Signature' => $signature,
            ])->timeout(10)->post($url, $payload);
        } catch (\Exception $e) {
            Log::error("Webhook dispatch failed: {$e->getMessage()}", [
                'event' => $event,
                'url' => $url,
            ]);
        }
    }
}
