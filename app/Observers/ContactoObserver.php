<?php

namespace App\Observers;

use App\Models\Contacto;

/**
 * Cuando se hace soft-delete de un Contacto, actualizar también el campo
 * `estado` a 'Inactivo' para mantener consistencia.
 *
 * Bug previo: el soft-delete solo rellenaba `deleted_at` pero dejaba
 * `estado='Activo'`, lo que hacía inconsistente el filtro de "solo activos".
 */
class ContactoObserver
{
    public function deleting(Contacto $contacto): void
    {
        if (! $contacto->isForceDeleting()) {
            $contacto->estado = 'Inactivo';
        }
    }
}
