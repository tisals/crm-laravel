<?php

namespace App\Application\UseCases\Sailus;

use App\Models\Contacto;
use App\Models\Servicio;
use Carbon\Carbon;

class ValidateLicenseUseCase
{
    public function execute(string $username, string $token, ?string $siteUrl = null, ?string $pluginVersion = null): ?array
    {
        // 1. Find the service by activation token
        $servicio = Servicio::where('activation_token', $token)->first();
        if (!$servicio) {
            return null; // unauthorized
        }

        // 2. Validate that the username matches a contact within the same organization
        $contacto = Contacto::where('entidad_id', $servicio->entidad_id)
            ->where('email_contacto', $username)
            ->first();

        if (!$contacto) {
            return null; // unauthorized
        }

        // 3. Determine license status (active or expired)
        // If fecha_fin is before today, it's expired.
        $status = 'active';
        if ($servicio->fecha_fin && Carbon::parse($servicio->fecha_fin)->lt(Carbon::today())) {
            $status = 'expired';
        }

        // Optionally store/log siteUrl or pluginVersion in service metadata or logs if needed in the future

        return [
            'success' => true,
            'status' => $status,
            'plan' => $servicio->plan_id,
            'tier' => $servicio->tier,
            'org_id' => $servicio->entidad_id,
            'contact_id' => $contacto->id,
            'service_id' => $servicio->id,
        ];
    }
}
