<?php

namespace App\Application\UseCases\Servicio;

use App\Models\Servicio;
use Carbon\Carbon;

class RenewServicioUseCase
{
    public function execute(int $id, array $data): ?array
    {
        $servicio = Servicio::find($id);
        if (!$servicio) {
            return null;
        }

        // Parse and update expiration date
        $newExpiresAt = Carbon::parse($data['new_expires_at']);
        $servicio->fecha_fin = $newExpiresAt;

        // Build metadata
        $existingMetadata = $servicio->metadata ?? [];
        $paymentMetadata = [
            'payment_id' => $data['payment_id'] ?? null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
        ];
        
        $servicio->metadata = array_merge($existingMetadata, $paymentMetadata);
        $servicio->save();

        return [
            'success' => true,
            'new_expires_at' => $newExpiresAt->toIso8601String(),
        ];
    }
}
