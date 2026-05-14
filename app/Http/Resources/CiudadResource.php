<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CiudadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'cod_municipio' => $this->cod_municipio,
            'nombre' => $this->nombre,
            'departamento' => $this->departamento,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
