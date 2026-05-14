<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DetalleOportunidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'oportunidad_id' => $this->oportunidad_id,
            'producto_id' => $this->producto_id,
            'concepto' => $this->concepto,
            'medida' => $this->medida,
            'cantidad' => $this->cantidad,
            'vr_unitario' => $this->vr_unitario,
            'iva' => $this->iva,
            'vr_total' => $this->vr_total,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
