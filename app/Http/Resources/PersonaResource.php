<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * PR-I 6a.13 — extends the Persona response with `tipo_persona`,
 * `entidad_id`, and a `relations` block (contacto_id, colaborador_id,
 * proveedor_id, entidad_id) per REQ-PRAPI-003.
 *
 * The `relations` block is computed at serialization time using small,
 * index-friendly queries against the three role tables. Each lookup picks
 * the most recent row by primary key DESC that links back to the persona
 * (a persona can have multiple role rows; we surface the newest as the
 * "current" one). When no relation exists, the field is null.
 *
 * Why not `whenLoaded`? The repository hands us a domain entity, not an
 * Eloquent model, so eager-loading would require a wider repository refactor.
 * The three extra selects are negligible (each is `WHERE persona_id = ?
 * ORDER BY id DESC LIMIT 1` over an indexed column), and the test was written
 * expecting them inline.
 */
class PersonaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'identificacion_tipo' => $this->identificacion_tipo,
            'identificacion_numero' => $this->identificacion_numero,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'email_principal' => $this->email_principal,
            'telefono_principal' => $this->telefono_principal,
            'direccion' => $this->direccion,
            'ciudad' => $this->ciudad,
            'pais' => $this->pais,
            // PR-A / PR-I: the iter4 fields show up in the response so
            // downstream (Mercury) can serialize the full person shape.
            'tipo_persona' => $this->tipo_persona ?? 'Natural',
            'entidad_id' => $this->entidad_id,
            'nombre_completo' => trim("{$this->nombres} {$this->apellidos}"),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'relations' => $this->resolveRelations(),
        ];
    }

    /**
     * Fetch the latest linked role row id for each of the three role
     * tables, plus resolve the effective entidad_id (personas.entidad_id
     * or the first entidad_persona pivot for this persona).
     *
     * Per commit fe99f70: `contacto.entidad_id` was dropped. The entidad
     * binding now lives in `entidad_persona`, keyed on persona_id.
     *
     * @return array{contacto_id: int|null, colaborador_id: int|null, proveedor_id: int|null, entidad_id: int|null}
     */
    private function resolveRelations(): array
    {
        $personaId = (int) $this->id;

        $contactoId = DB::table('contacto')
            ->where('persona_id', $personaId)
            ->orderByDesc('id')
            ->value('id');

        $colaboradorId = DB::table('colaboradores')
            ->where('persona_id', $personaId)
            ->orderByDesc('id')
            ->value('id');

        $proveedorId = DB::table('proveedores')
            ->where('persona_id', $personaId)
            ->orderByDesc('id')
            ->value('id');

        // entidad_id comes from the persona itself if set; otherwise we
        // fall back to whichever entidad the persona is bound to via the
        // `entidad_persona` pivot (per spec REQ-PRAPI-003 `relations.entidad_id`).
        $entidadId = $this->entidad_id !== null
            ? (int) $this->entidad_id
            : DB::table('entidad_persona')
                ->where('persona_id', $personaId)
                ->orderBy('entidad_id')
                ->value('entidad_id');

        return [
            'contacto_id' => $contactoId ? (int) $contactoId : null,
            'colaborador_id' => $colaboradorId ? (int) $colaboradorId : null,
            'proveedor_id' => $proveedorId ? (int) $proveedorId : null,
            'entidad_id' => $entidadId !== null ? (int) $entidadId : null,
        ];
    }
}
