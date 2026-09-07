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
     * the legacy 'Ganada' state), open a `cliente` pivot row on the related
     * entity. Commit 5.5 replaced the legacy `entidad.cliente_desde` column
     * with the `entidad_relacion` pivot; `Entidad::markAsCliente()` keeps
     * the original "stamp on first win only" semantics.
     */
    public function created(Oportunidad $oportunidad): void
    {
        $etapaCodigo = $oportunidad->pipelineEtapa?->codigo
            ?? PipelineEtapa::find($oportunidad->pipeline_etapa_id)?->codigo;

        if ($etapaCodigo === 'ACEPTADA') {
            $entidad = Entidad::find($oportunidad->entidad_id);
            $entidad?->markAsCliente();
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
            $entidad = Entidad::find($oportunidad->entidad_id);
            $entidad?->markAsCliente();
        } elseif ($oldEtapaCodigo === 'ACEPTADA' && $newEtapaCodigo !== 'ACEPTADA') {
            $hasOtherWon = Oportunidad::where('entidad_id', $oportunidad->entidad_id)
                ->whereHas('pipelineEtapa', fn ($q) => $q->where('codigo', 'ACEPTADA'))
                ->where('id', '!=', $oportunidad->id)
                ->exists();

            if (! $hasOtherWon) {
                // No other won opps — close the `cliente` pivot row.
                // Pre-Commit 5.5 this cleared `entidad.cliente_desde` and
                // flipped `entidad.estado` back to 'Activo'. Commit 5.5
                // removes the column; the closing pivot row carries the
                // same semantic ("the entity is no longer a cliente").
                $entidad = Entidad::find($oportunidad->entidad_id);
                $entidad?->clearCliente();
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
