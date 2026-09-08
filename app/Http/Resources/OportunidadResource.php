<?php

namespace App\Http\Resources;

use App\Enums\ProjectionLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commit 6 (tenant-data-model-correction) — depth-aware projection.
 *
 *   - Shallow (depth=1): bare oportunidad row, no `entidad.*`, no
 *     `detalles[]`, no `valor`. The `entidad_nombre` /
 *     `entidad_identificacion` fields fall back to the `"#{$id}"`
 *     sentinel so the key is still present on the payload (clients
 *     that expect it don't need to special-case shallow responses).
 *
 *   - Default (depth=2): the canonical detail shape. Includes the
 *     `entidad_nombre` / `entidad_identificacion` flat fields (resolved
 *     from the eager-loaded `entidad` relation if present), plus the
 *     `detalles[]` collection and the aggregated `valor`. The
 *     `pipelineEtapa` is loaded on demand for the `estado` display name.
 *
 *   - Deep (depth=3): + `entidad` and `detalles.producto` as nested
 *     objects (full second-degree relations) and `detalles[].producto`.
 *     Reserved for webhook snapshots and the Mercury mirror.
 */
class OportunidadResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        $level = $this->depth($request);
        $isShallow = $level === ProjectionLevel::Shallow;
        $isDeep = $level === ProjectionLevel::Deep;

        if (! $isShallow && $this->pipeline_etapa_id && (! $this->relationLoaded('pipelineEtapa') || $this->pipelineEtapa?->id !== $this->pipeline_etapa_id)) {
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

        if ($isShallow) {
            // Bare shape — no `entidad_nombre`, no `detalles`, no `valor`.
            // List endpoints consume this; detail endpoints consume default.
            $arr['entidad_nombre'] = $this->entidad_id ? "#{$this->entidad_id}" : null;

            return $arr;
        }

        if ($this->relationLoaded('entidad')) {
            $arr['entidad_nombre'] = $this->entidad->nombre;
            $arr['entidad_identificacion'] = $this->entidad->identificacion;
        } else {
            $arr['entidad_nombre'] = $this->entidad_id ? "#{$this->entidad_id}" : null;
        }

        if ($this->relationLoaded('detalles')) {
            $arr['valor'] = $this->detalles->sum('vr_total');

            if ($isDeep) {
                // Deep: each detalle carries the nested `producto` object
                // (second-degree relation). Product data is shallow by
                // default to avoid N+1 in list endpoints.
                $arr['detalles'] = $this->detalles->map(fn ($d) => [
                    'id' => $d->id,
                    'producto_id' => $d->producto_id,
                    'concepto' => $d->concepto,
                    'cantidad' => $d->cantidad,
                    'vr_unitario' => $d->vr_unitario,
                    'iva' => $d->iva,
                    'vr_total' => $d->vr_total,
                    'producto' => $d->relationLoaded('producto') && $d->producto
                        ? [
                            'id' => $d->producto->id,
                            'nombre' => $d->producto->nombre,
                            'referencia' => $d->producto->referencia,
                            'descripcion' => $d->producto->descripcion ?? null,
                        ]
                        : null,
                ]);
            } else {
                // Default: detalles without nested producto object.
                $arr['detalles'] = $this->detalles->map(fn ($d) => [
                    'id' => $d->id,
                    'producto_id' => $d->producto_id,
                    'concepto' => $d->concepto,
                    'cantidad' => $d->cantidad,
                    'vr_unitario' => $d->vr_unitario,
                    'iva' => $d->iva,
                    'vr_total' => $d->vr_total,
                ]);
            }
        }

        // Deep: surface the linked entidad as a nested object so callers
        // can read `entidad.nombre`, `entidad.estado`, etc. without a
        // roundtrip. Eager-load is the caller's responsibility.
        if ($isDeep && $this->relationLoaded('entidad') && $this->entidad) {
            $arr['entidad'] = [
                'id' => $this->entidad->id,
                'nombre' => $this->entidad->nombre,
                'identificacion' => $this->entidad->identificacion,
                'tipo_persona' => $this->entidad->tipo_persona,
            ];
        }

        return $arr;
    }
}