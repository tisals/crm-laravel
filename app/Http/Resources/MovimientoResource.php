<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha,
            'valor_debito' => $this->valor_debito,
            'valor_credito' => $this->valor_credito,
            'proveedor_id' => $this->proveedor_id,
            'colaborador_id' => $this->colaborador_id,
            'servicio_id' => $this->servicio_id,
            'observaciones' => $this->observaciones,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
