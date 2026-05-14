<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenServicioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'detalle_srv_id' => $this->detalle_srv_id,
            'colaborador_id' => $this->colaborador_id,
            'proveedor_id' => $this->proveedor_id,
            'contacto_id' => $this->contacto_id,
            'descripcion' => $this->descripcion,
            'objetivo' => $this->objetivo,
            'ubicacion' => $this->ubicacion,
            'fecha_desde' => $this->fecha_desde,
            'fecha_hasta' => $this->fecha_hasta,
            'valor' => $this->valor,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
