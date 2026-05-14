<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColaboradorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usuario_id' => $this->usuario_id,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'tipo_id' => $this->tipo_id,
            'identificacion' => $this->identificacion,
            'cargo' => $this->cargo,
            'area' => $this->area,
            'fecha_ingreso' => $this->fecha_ingreso,
            'fecha_retiro' => $this->fecha_retiro,
            'contrato' => $this->contrato,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
