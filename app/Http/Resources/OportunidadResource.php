<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OportunidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->pipeline_etapa_id && (! $this->relationLoaded('pipelineEtapa') || $this->pipelineEtapa?->id !== $this->pipeline_etapa_id)) {
            $this->load('pipelineEtapa');
        }

        $arr = [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'entidad_id' => $this->entidad_id,
            'contacto_id' => $this->contacto_id,
            'fecha' => $this->fecha,
            'fuente_canal' => $this->fuente_canal,
            'estado' => $this->pipelineEtapa ? $this->pipelineEtapa->nombre : $this->estado,
            'estado_registro' => $this->estado, // Actual active/inactive state
            'pipeline_id' => $this->pipeline_id,
            'pipeline_etapa_id' => $this->pipeline_etapa_id,
            'parent_id' => $this->parent_id,
            'version' => $this->version,
            'is_latest' => $this->is_latest,
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
            $arr['detalles'] = $this->detalles->map(fn ($d) => [
                'id' => $d->id,
                'producto_id' => $d->producto_id,
                'concepto' => $d->concepto,
                'cantidad' => $d->cantidad,
                'vr_unitario' => $d->vr_unitario,
                'iva' => $d->iva,
                'vr_total' => $d->vr_total,
                'producto' => $d->relationLoaded('producto') && $d->producto
                    ? ['id' => $d->producto->id, 'nombre' => $d->producto->nombre, 'referencia' => $d->producto->referencia]
                    : null,
            ]);
        }

        return $arr;
    }
}
