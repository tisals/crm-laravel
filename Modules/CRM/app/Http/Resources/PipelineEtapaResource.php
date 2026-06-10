<?php

namespace Modules\CRM\Http\Resources;

use App\Domain\Entities\PipelineEtapa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineEtapaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PipelineEtapa $etapa */
        $etapa = $this->resource;

        return [
            'id' => $etapa->id,
            'pipeline_id' => $etapa->pipeline_id,
            'nombre' => $etapa->nombre,
            'orden' => $etapa->orden,
            'habilitado' => $etapa->habilitado,
            'created_at' => $etapa->created_at,
            'updated_at' => $etapa->updated_at,
        ];
    }
}
