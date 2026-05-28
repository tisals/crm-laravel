<?php

namespace App\Http\Resources;

use App\Domain\Entities\Ciudad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CiudadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Ciudad $ciudad */
        $ciudad = $this->resource;

        return [
            'cod_municipio' => $ciudad->cod_municipio,
            'nombre' => $ciudad->nombre,
            'departamento' => $ciudad->departamento,
            'created_at' => $ciudad->created_at,
            'updated_at' => $ciudad->updated_at,
        ];
    }
}
