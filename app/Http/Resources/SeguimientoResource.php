<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeguimientoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'oportunidad_id' => $this->oportunidad_id,
            // PR-H (Phase 5b - REQ-SEG-004): swap `contacto_id` for
            // `persona_id` in the JSON envelope. The DB column was
            // dropped by PR-G; the FK swap now resolves to personas.id.
            'persona_id' => $this->persona_id,
            'entidad_id' => $this->entidad_id,
            'tipo' => $this->tipo,
            'fecha' => $this->fecha,
            'hora' => $this->hora,
            'fecha_fin' => $this->fecha_fin,
            'notas' => $this->notas,
            'autor_id' => $this->autor_id,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Appends from the Eloquent model — `contacto_nombre` is
            // kept as the JSON key for backward compatibility with the
            // frontend dashboard but the accessor now resolves from
            // `persona.nombres + persona.apellidos` (post-PR-G
            // canonical identity axis).
            'entidad_nombre' => $this->entidad_nombre,
            'contacto_nombre' => $this->contacto_nombre,
            'oportunidad_codigo' => $this->oportunidad_codigo,
            'autor_nombre' => $this->autor_nombre,
        ];
    }
}
