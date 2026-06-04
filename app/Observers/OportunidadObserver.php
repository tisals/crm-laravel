<?php

namespace App\Observers;

use App\Models\Oportunidad;
use Modules\CRM\Models\PipelineEtapa;

class OportunidadObserver
{
    /**
     * Handle Oportunidad created event.
     * If created directly as 'Ganada', set cliente_desde.
     */
    public function created(Oportunidad $oportunidad): void
    {
        $etapaNombre = $oportunidad->pipelineEtapa?->nombre ?? (PipelineEtapa::find($oportunidad->pipeline_etapa_id)?->nombre);

        if ($etapaNombre === 'Ganada') {
            \App\Models\Entidad::where('id', $oportunidad->entidad_id)
                ->whereNull('cliente_desde')
                ->update(['cliente_desde' => now()]);
        }
    }

    /**
     * Handle the Oportunidad "updated" event.
     * Triggered when estado changes via $model->update() or $model->save().
     */
    public function updated(Oportunidad $oportunidad): void
    {
        if (!$oportunidad->isDirty('pipeline_etapa_id')) {
            return;
        }

        $oldEtapaNombre = PipelineEtapa::find($oportunidad->getOriginal('pipeline_etapa_id'))?->nombre;
        $newEtapaNombre = PipelineEtapa::find($oportunidad->pipeline_etapa_id)?->nombre;

        if ($newEtapaNombre === 'Ganada' && $oldEtapaNombre !== 'Ganada') {
            // Changed TO 'Ganada' — set cliente_desde if not already set
            \App\Models\Entidad::where('id', $oportunidad->entidad_id)
                ->whereNull('cliente_desde')
                ->update(['cliente_desde' => now()]);
        } elseif ($oldEtapaNombre === 'Ganada' && $newEtapaNombre !== 'Ganada') {
            // Changed FROM 'Ganada' (to any other state) — clear cliente_desde
            // and set entidad state back to Activo if no other won opportunities exist
            $hasOtherWon = Oportunidad::where('entidad_id', $oportunidad->entidad_id)
                ->whereHas('pipelineEtapa', fn($q) => $q->where('nombre', 'Ganada'))
                ->where('id', '!=', $oportunidad->id)
                ->exists();

            if (!$hasOtherWon) {
                \App\Models\Entidad::where('id', $oportunidad->entidad_id)
                    ->update([
                        'cliente_desde' => null,
                        'estado' => 'Activo'
                    ]);
            }
        }
    }
}

