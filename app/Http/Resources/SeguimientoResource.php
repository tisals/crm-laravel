<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeguimientoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'oportunidad_id' => $this->oportunidad_id,
            'contacto_id' => $this->contacto_id,
            'entidad_id' => $this->entidad_id,
            'tipo' => $this->tipo,
            'fecha' => $this->fecha,
            'hora' => $this->hora,
            'fecha_fin' => $this->fecha_fin,
            'notas' => $this->notas,
            'autor_id' => $this->autor_id,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
