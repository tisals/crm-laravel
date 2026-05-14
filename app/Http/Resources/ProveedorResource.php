<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo_id' => $this->tipo_id,
            'identificacion' => $this->identificacion,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'profesion' => $this->profesion,
            'especialidad' => $this->especialidad,
            'iva' => $this->iva,
            'retenciones' => $this->retenciones,
            'ciudad_cod' => $this->ciudad_cod,
            'fecha_registro' => $this->fecha_registro,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
