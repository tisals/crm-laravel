<?php

namespace App\Application\UseCases\Oportunidad;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CRM\Models\Oportunidad;
use Modules\CRM\Models\PipelineEtapa;

class BulkMoveOportunidadesToPipelineUseCase
{
    /**
     * Execute the bulk move of oportunidades to a target pipeline etapa.
     *
     * @param  int[]  $oportunidadIds
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function execute(array $oportunidadIds, int $targetPipelineEtapaId): array
    {
        // 1. Validate target etapa exists
        $etapa = PipelineEtapa::find($targetPipelineEtapaId);
        if (! $etapa || ! $etapa->habilitado) {
            throw ValidationException::withMessages([
                'target_pipeline_etapa_id' => ['La etapa especificada no existe o no está habilitada.'],
            ]);
        }

        // 2. Find all oportunidades
        $oportunidades = Oportunidad::whereIn('id', $oportunidadIds)->get();
        $foundIds = $oportunidades->pluck('id')->toArray();
        $missingIds = array_values(array_diff($oportunidadIds, $foundIds));

        if (! empty($missingIds)) {
            throw ValidationException::withMessages([
                'invalid_ids' => $missingIds,
            ]);
        }

        // 3. Transactional update: move each oportunidad to the target etapa
        DB::transaction(function () use ($oportunidades, $targetPipelineEtapaId) {
            foreach ($oportunidades as $oportunidad) {
                $oportunidad->update(['pipeline_etapa_id' => $targetPipelineEtapaId]);
            }
        });

        return [
            'moved_count' => count($oportunidadIds),
            'oportunidad_ids' => $oportunidadIds,
        ];
    }
}
