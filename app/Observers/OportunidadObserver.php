<?php

namespace App\Observers;

use App\Models\Oportunidad;

class OportunidadObserver
{
    /**
     * Handle Oportunidad created event.
     * If created directly as 'Ganada', set cliente_desde.
     */
    public function created(Oportunidad $oportunidad): void
    {
        if ($oportunidad->estado === 'Ganada') {
            \App\Models\Entidad::where('id', $oportunidad->entidad_id)
                ->whereNull('cliente_desde')
                ->update(['cliente_desde' => now()]);
        }
    }

    /**
     * Handle the Oportunidad "updated" event.
     * Triggered when estado changes via $model->update() or $model->save().
     *
     * - If estado changed TO 'Ganada' AND entidad.cliente_desde is null → set cliente_desde = now()
     * - If estado changed FROM 'Ganada' (to any other state) → set cliente_desde = null
     */
    public function updated(Oportunidad $oportunidad): void
    {
        if (!$oportunidad->isDirty('estado') && !$oportunidad->wasChanged('estado')) {
            return;
        }

        $originalEstado = $oportunidad->getOriginal('estado');

        if ($oportunidad->estado === 'Ganada' && $originalEstado !== 'Ganada') {
            // Changed TO 'Ganada' — set cliente_desde if not already set
            \App\Models\Entidad::where('id', $oportunidad->entidad_id)
                ->whereNull('cliente_desde')
                ->update(['cliente_desde' => now()]);
        } elseif ($originalEstado === 'Ganada' && $oportunidad->estado !== 'Ganada') {
            // Changed FROM 'Ganada' (to any other state) — clear cliente_desde
            \App\Models\Entidad::where('id', $oportunidad->entidad_id)
                ->update(['cliente_desde' => null]);
        }
    }
}
