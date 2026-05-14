<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LugarEntidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entidad_id' => $this->entidad_id,
            'area_oficina' => $this->area_oficina,
            'direccion' => $this->direccion,
            'direccion_adicional' => $this->direccion_adicional,
            'ciudad_cod' => $this->ciudad_cod,
            'contacto_id' => $this->contacto_id,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
