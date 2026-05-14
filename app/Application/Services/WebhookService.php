<?php

namespace App\Application\Services;

use App\Models\Entidad;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Enviar webhook a la entidad configurada.
     * Se ejecuta de forma asíncrona (en cola) para no bloquear la respuesta API.
     */
    public function dispatch(Entidad $entidad, string $event, array $payload): void
    {
        if (!$entidad->hasWebhooksEnabled()) {
            return;
        }

        $config = $entidad->getWebhookConfig();
        
        // Preparar payload con firma HMAC-SHA256
        $timestamp = time();
        $body = json_encode([
            'event' => $event,
            'timestamp' => $timestamp,
            'data' => $payload,
        ]);

        $signature = $this->generateSignature($body, $config['secret'], $timestamp);

        try {
            // Enviar de forma asíncrona (non-blocking)
            Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Event' => $event,
                ])
                ->post($config['url'], json_decode($body, true));

            Log::info('Webhook despachado', [
                'entidad_id' => $entidad->id,
                'event' => $event,
                'url' => $config['url'],
            ]);
        } catch (\Exception $e) {
            Log::error('Webhook fallido', [
                'entidad_id' => $entidad->id,
                'event' => $event,
                'error' => $e->getMessage(),
                'url' => $config['url'],
            ]);
        }
    }

    /**
     * Generar firma HMAC-SHA256 para el payload.
     * Formato: sha256=timestamp.body.secret
     */
    private function generateSignature(string $body, ?string $secret, int $timestamp): string
    {
        if (empty($secret)) {
            return 'sha256=unsigned';
        }

        $signature = $timestamp . '.' . $body . '.' . $secret;
        return 'sha256=' . hash_hmac('sha256', $signature, $secret);
    }

    /**
     * Eventos disponibles para webhooks:
     * - entidad.created, entidad.updated, entidad.deleted
     * - oportunidad.created, oportunidad.updated, oportunidad.estado_changed
     * - contacto.created, contacto.updated, contacto.deleted
     * - servicio.created, servicio.updated
     */
}