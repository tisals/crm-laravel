<?php

namespace App\Http\Resources;

use App\Domain\Entities\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Http\Resources\PipelineEtapaResource;

class PipelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Pipeline $pipeline */
        $pipeline = $this->resource;

        return [
            'id' => $pipeline->id,
            'nombre' => $pipeline->nombre,
            'codigo' => $pipeline->codigo,
            'habilitado' => $pipeline->habilitado,
            'etapas' => PipelineEtapaResource::collection($pipeline->etapas ?? []),
            'created_at' => $pipeline->created_at,
            'updated_at' => $pipeline->updated_at,
        ];
    }
}
