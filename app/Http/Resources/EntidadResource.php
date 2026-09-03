<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Commit 4: the `direccion`, `ciudad_cod`, `dominio`, `email`,
 * `telefono` fields no longer exist on `entidad`. The corresponding
 * canonical values live in the shared `emails` / `telefonos` /
 * `direcciones` / `presencia_online` tables. We still surface
 * `email`, `telefono`, `direccion`, `dominio` in the response for
 * API compatibility — they now resolve from the principal rows of
 * the new tables.
 */
class EntidadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $entidadId = (int) $this->id;

        return [
            'id' => $this->id,
            'tipo_persona' => $this->tipo_persona,
            'tipo_id' => $this->tipo_id,
            'identificacion' => $this->identificacion,
            'nombre' => $this->nombre,
            'nombre_comercial' => $this->nombre_comercial,
            'direccion' => $this->principalDireccion($entidadId),
            'ciudad_cod' => $this->principalDireccionCod($entidadId),
            'dominio' => $this->principalDominio($entidadId),
            'email' => $this->principalEmailTrabajo($entidadId),
            'telefono' => $this->principalTelefonoTrabajo($entidadId),
            'cantidad_empleados' => $this->cantidad_empleados,
            'rut' => $this->rut,
            'logo' => $this->logo,
            'estado' => $this->estado,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function principalDireccion(int $entidadId): ?string
    {
        return DB::table('direcciones')
            ->where('entidad_id', $entidadId)
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('direccion_principal');
    }

    private function principalDireccionCod(int $entidadId): ?string
    {
        return DB::table('direcciones')
            ->where('entidad_id', $entidadId)
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('ciudad_codigo');
    }

    private function principalDominio(int $entidadId): ?string
    {
        return DB::table('presencia_online')
            ->where('entidad_id', $entidadId)
            ->where('tipo', 'web')
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('url');
    }

    private function principalEmailTrabajo(int $entidadId): ?string
    {
        return DB::table('emails')
            ->where('entidad_id', $entidadId)
            ->where('tipo', 'trabajo')
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('email');
    }

    private function principalTelefonoTrabajo(int $entidadId): ?string
    {
        return DB::table('telefonos')
            ->where('entidad_id', $entidadId)
            ->where('tipo', 'trabajo')
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('numero');
    }
}
