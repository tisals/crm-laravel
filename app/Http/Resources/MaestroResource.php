<?php

namespace App\Http\Resources;

use App\Domain\Entities\Maestro;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaestroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Maestro $maestro */
        $maestro = $this->resource;

        return [
            'id' => $maestro->id,
            'nombre' => $maestro->nombre,
            'campo' => $maestro->campo,
            'habilitado' => $maestro->habilitado,
            'created_at' => $maestro->created_at,
            'updated_at' => $maestro->updated_at,
        ];
    }
}
