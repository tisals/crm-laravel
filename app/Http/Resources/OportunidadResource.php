<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OportunidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $arr = [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'entidad_id' => $this->entidad_id,
            'contacto_id' => $this->contacto_id,
            'fecha' => $this->fecha,
            'fuente_canal' => $this->fuente_canal,
            'estado' => $this->estado,
            'observaciones' => $this->observaciones,
            'aclaraciones' => $this->aclaraciones,
            'validez_oferta' => $this->validez_oferta,
            'tiempo_entrega' => $this->tiempo_entrega,
            'forma_pago' => $this->forma_pago,
            'garantia' => $this->garantia,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if ($this->relationLoaded('entidad')) {
            $arr['entidad_nombre'] = $this->entidad->nombre;
            $arr['entidad_identificacion'] = $this->entidad->identificacion;
        } else {
            $arr['entidad_nombre'] = $this->entidad_id ? "#{$this->entidad_id}" : null;
        }

        if ($this->relationLoaded('detalles')) {
            $arr['valor'] = $this->detalles->sum('vr_total');
        }

        return $arr;
    }
}
