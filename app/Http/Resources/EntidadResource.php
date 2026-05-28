<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo_persona' => $this->tipo_persona,
            'tipo_id' => $this->tipo_id,
            'identificacion' => $this->identificacion,
            'nombre' => $this->nombre,
            'nombre_comercial' => $this->nombre_comercial,
            'direccion' => $this->direccion,
            'ciudad_cod' => $this->ciudad_cod,
            'dominio' => $this->dominio,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'rut' => $this->rut,
            'logo' => $this->logo,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
