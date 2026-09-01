<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Per commit fe99f70: `contacto.entidad_id` was dropped. The
        // contacto's entidad binding lives in the `entidad_persona` pivot
        // (via the underlying persona). We expose the FIRST pivot's
        // entidad_id as the legacy `entidad_id` field for API consumers
        // that still depend on it.
        $entidadId = null;
        if ($this->persona_id !== null) {
            $entidadId = \Illuminate\Support\Facades\DB::table('entidad_persona')
                ->where('persona_id', $this->persona_id)
                ->orderBy('entidad_id')
                ->value('entidad_id');
        }

        return [
            'id' => $this->id,
            'entidad_id' => $entidadId !== null ? (int) $entidadId : null,
            'entidad_nombre' => $this->entidad_nombre ?? $this->entidad?->nombre,
            'persona_id' => $this->persona_id,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'area' => $this->area,
            'cargo' => $this->cargo,
            'tel_contacto' => $this->tel_contacto,
            'movil' => $this->movil,
            'email_contacto' => $this->email_contacto,
            'email_secundario' => $this->email_secundario,
            'rol' => $this->rol,
            'etapa' => $this->etapa,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'diagnostico_data' => $this->diagnostico_data,
            'fuente' => $this->fuente,
        ];
    }
}
