<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DetalleServicioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'servicio_id' => $this->servicio_id,
            'producto_id' => $this->producto_id,
            'observacion' => $this->observacion,
            'cantidad' => $this->cantidad,
            'precio' => $this->precio,
            'descuento' => $this->descuento,
            'sub_total' => $this->sub_total,
            'iva' => $this->iva,
            'total' => $this->total,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
