<?php

namespace App\Application\Seguimiento\Services;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Modules\CRM\Models\Seguimiento;

/**
 * Resolves the recipients for a FollowUpNotification.
 *
 * Priority order:
 *   1. Comercials mapped to the seguimiento's entidad via `entidad_usuario`
 *      (status=Activo, rol=Comercial).
 *   2. Fallback: all Admin and SuperAdmin users (status=Activo).
 *
 * Returns an empty collection if neither group has any recipient.
 *
 * Extracted from ContactoAccionController so it's independently testable
 * and reusable (e.g., from any other place that sends follow-ups).
 */
class NotificacionRecipientsResolver
{
    /**
     * @return Collection<int, Usuario>
     */
    public function resolve(Seguimiento $seguimiento): Collection
    {
        // Resolve comercial role id
        $comercialRolId = Rol::where('nombre', 'Comercial')->value('id');

        if ($comercialRolId && $seguimiento->entidad_id) {
            $comerciales = Usuario::where('rol_id', $comercialRolId)
                ->where('estado', 'Activo')
                ->whereIn('id', function ($q) use ($seguimiento) {
                    $q->select('usuario_id')
                        ->from('entidad_usuario')
                        ->where('entidad_id', $seguimiento->entidad_id);
                })
                ->get();

            if ($comerciales->isNotEmpty()) {
                return $comerciales;
            }
        }

        // Fallback: admins
        $adminRolIds = Rol::whereIn('nombre', ['Admin', 'SuperAdmin'])->pluck('id');
        if ($adminRolIds->isNotEmpty()) {
            return Usuario::whereIn('rol_id', $adminRolIds)
                ->where('estado', 'Activo')
                ->get();
        }

        return collect();
    }
}
