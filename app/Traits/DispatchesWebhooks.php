<?php

namespace App\Traits;

use App\Application\Services\WebhookService;
use App\Models\Entidad;

trait DispatchesWebhooks
{
    /**
     * Dispatch webhook after entity operation.
     * Acepta tanto Domain Entities como Eloquent Models.
     */
    protected function dispatchWebhook($entidad, string $event, array $data): void
    {
        try {
            // Si es un Domain Entity (App\Domain\Entities\Entidad), convertir a Eloquent Model
            if ($entidad instanceof \App\Domain\Entities\Entidad) {
                $entidad = \App\Models\Entidad::find($entidad->id);
            }

            // Si no se encontró o no tiene webhooks, salir
            if (!$entidad instanceof \App\Models\Entidad || !$entidad->hasWebhooksEnabled()) {
                return;
            }

            $webhookService = app(WebhookService::class);
            $webhookService->dispatch($entidad, $event, $data);
        } catch (\Exception $e) {
            // No fallar la operación principal si el webhook falla
            Log::warning('Webhook dispatch falló (no crítico)', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Helper para obtener la entidad del contexto de request.
     * Útil cuando el controller tiene acceso a la entidad relacionada.
     */
    protected function getEntidadForWebhook($model): ?Entidad
    {
        if ($model instanceof Entidad) {
            return $model;
        }

        // Buscar relación entidad en el modelo
        if (method_exists($model, 'entidad')) {
            return $model->entidad;
        }

        // Buscar por foreign key
        if (!empty($model->entidad_id)) {
            return Entidad::find($model->entidad_id);
        }

        return null;
    }
}