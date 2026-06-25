<?php

namespace App\Observers;

use App\Events\PipelineEtapaChanged;
use App\Models\Entidad;
use Modules\CRM\Models\Oportunidad;
use Modules\CRM\Models\PipelineEtapa;

class OportunidadObserver
{
    /**
     * Handle Oportunidad created event.
     * If created directly on the 'ACEPTADA' stage (the canonical equivalent of
     * the legacy 'Ganada' state), set cliente_desde on the related entity.
     */
    public function created(Oportunidad $oportunidad): void
    {
        $etapaCodigo = $oportunidad->pipelineEtapa?->codigo
            ?? PipelineEtapa::find($oportunidad->pipeline_etapa_id)?->codigo;

        if ($etapaCodigo === 'ACEPTADA') {
            Entidad::where('id', $oportunidad->entidad_id)
                ->whereNull('cliente_desde')
                ->update(['cliente_desde' => now()]);
        }
    }

    /**
     * Handle the Oportunidad "updated" event.
     * Triggered when pipeline_etapa_id changes via $model->update() or $model->save().
     */
    public function updated(Oportunidad $oportunidad): void
    {
        $oldEtapaId = $oportunidad->getOriginal('pipeline_etapa_id');
        $newEtapaId = $oportunidad->pipeline_etapa_id;

        if ($oldEtapaId == $newEtapaId) {
            return;
        }

        $oldEtapaCodigo = PipelineEtapa::find($oldEtapaId)?->codigo;
        $newEtapaCodigo = PipelineEtapa::find($newEtapaId)?->codigo;

        if ($newEtapaCodigo === 'ACEPTADA' && $oldEtapaCodigo !== 'ACEPTADA') {
            Entidad::where('id', $oportunidad->entidad_id)
                ->whereNull('cliente_desde')
                ->update(['cliente_desde' => now()]);
        } elseif ($oldEtapaCodigo === 'ACEPTADA' && $newEtapaCodigo !== 'ACEPTADA') {
            $hasOtherWon = Oportunidad::where('entidad_id', $oportunidad->entidad_id)
                ->whereHas('pipelineEtapa', fn ($q) => $q->where('codigo', 'ACEPTADA'))
                ->where('id', '!=', $oportunidad->id)
                ->exists();

            if (! $hasOtherWon) {
                Entidad::where('id', $oportunidad->entidad_id)
                    ->update([
                        'cliente_desde' => null,
                        'estado' => 'Activo',
                    ]);
            }
        }

        // Dispatch PipelineEtapaChanged event for webhook listener
        $oldEtapa = PipelineEtapa::find($oldEtapaId);
        $newEtapa = PipelineEtapa::find($newEtapaId);

        if ($newEtapa) {
            PipelineEtapaChanged::dispatch(
                oportunidadId: $oportunidad->id,
                previousEtapaId: $oldEtapa?->id,
                newEtapaId: $newEtapa->id,
                pipelineId: $newEtapa->pipeline_id,
                userId: $oportunidad->updated_by ?? $oportunidad->created_by,
            );
        }
    }
}
