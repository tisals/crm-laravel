<?php

namespace App\Http\Resources;

use App\Enums\ProjectionLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * PR-I 6a.13 — extends the Persona response with `tipo_persona`,
 * `entidad_id`, and a `relations` block (contacto_id, colaborador_id,
 * proveedor_id, entidad_id) per REQ-PRAPI-003.
 *
 * Commit 6 (tenant-data-model-correction) — depth-aware projection:
 * the resource honours the `?depth=` query param:
 *
 *   - Shallow (depth=1): bare identity fields. NO `relations` block,
 *     NO primary-email/telefono/direccion lookups. This is the cheap
 *     path for list endpoints (dropdowns, infinite scroll pages).
 *   - Default (depth=2): identity + primary email/telefono/direccion
 *     + the `relations` block. Canonical detail shape.
 *   - Deep    (depth=3): + a flat `entidad` snapshot with the linked
 *     entidad's `nombre` / `identificacion`, so downstream consumers
 *     (Mercury mirror, webhook snapshots) don't need a second roundtrip.
 *
 * The `relations` block uses small, index-friendly queries against the
 * three role tables when active. Each lookup picks the most recent row
 * by primary key DESC that links back to the persona (a persona can
 * have multiple role rows; we surface the newest as the "current" one).
 * When no relation exists, the field is null.
 *
 * Why not `whenLoaded`? The repository hands us a domain entity, not an
 * Eloquent model, so eager-loading would require a wider repository refactor.
 * The extra selects are negligible (each is `WHERE persona_id = ?
 * ORDER BY id DESC LIMIT 1` over an indexed column), and the test was
 * written expecting them inline.
 */
class PersonaResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        // Commit 4 dropped `email_principal`, `telefono_principal`,
        // `direccion`, `ciudad`, `pais` — those values now live in the
        // shared `emails` / `telefonos` / `direcciones` tables. The
        // `relations` block below surfaces them via sub-queries that
        // pick the primary row per contact table (es_principal=true).
        //
        // Commit 6 — at Shallow projection we strip the primary-row
        // lookups AND the role-table joins to keep list responses cheap.
        $level = $this->depth($request);
        $isDeep = $level === ProjectionLevel::Deep;
        $isShallow = $level === ProjectionLevel::Shallow;

        $base = [
            'id' => $this->id,
            'identificacion_tipo' => $this->identificacion_tipo,
            'identificacion_numero' => $this->identificacion_numero,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            // PR-A / PR-I: the iter4 fields show up in the response so
            // downstream (Mercury) can serialize the full person shape.
            'tipo_persona' => $this->tipo_persona ?? 'Natural',
            'entidad_id' => $this->entidad_id,
            'nombre_completo' => trim("{$this->nombres} {$this->apellidos}"),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        // Identity-only payload: list endpoints use this.
        if ($isShallow) {
            return $base;
        }

        $base['email_principal'] = $this->primaryEmail((int) $this->id);
        $base['telefono_principal'] = $this->primaryTelefono((int) $this->id);
        $base['direccion'] = $this->primaryDireccion((int) $this->id)['direccion_principal'] ?? null;
        $base['ciudad'] = $this->primaryDireccion((int) $this->id)['nombre_sede'] ?? null;
        $base['pais'] = $this->primaryDireccion((int) $this->id)['pais'] ?? null;
        $base['relations'] = $this->resolveRelations();

        // Deep projection: include the linked entidad's identity so the
        // Mercury mirror doesn't have to roundtrip. We resolve via the
        // pivot (entidad_persona) because persona.entidad_id may be null.
        if ($isDeep) {
            $base['entidad'] = $this->resolveEntidadSnapshot();
        }

        return $base;
    }

    /** Primary email row (es_principal=true) for a persona, or null. */
    private function primaryEmail(int $personaId): ?string
    {
        $row = DB::table('emails')
            ->where('persona_id', $personaId)
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->first(['email']);

        return $row?->email;
    }

    /** Primary telefono row (es_principal=true) for a persona, or null. */
    private function primaryTelefono(int $personaId): ?string
    {
        $row = DB::table('telefonos')
            ->where('persona_id', $personaId)
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->first(['numero']);

        return $row?->numero;
    }

    /**
     * Primary direccion row (es_principal=true) for a persona, or empty
     * array. We keep the shape flat (each key optional) so callers can
     * read `$persona->primary_direccion['direccion_principal']` without
     * hitting a nested object.
     *
     * @return array{direccion_principal: ?string, nombre_sede: ?string, pais: ?string}
     */
    private function primaryDireccion(int $personaId): array
    {
        $row = DB::table('direcciones')
            ->where('persona_id', $personaId)
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->first(['direccion_principal', 'nombre_sede', 'pais']);

        if (! $row) {
            return ['direccion_principal' => null, 'nombre_sede' => null, 'pais' => null];
        }

        return [
            'direccion_principal' => $row->direccion_principal,
            'nombre_sede' => $row->nombre_sede,
            'pais' => $row->pais,
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

    /**
     * Flat entidad snapshot for deep projection. Resolves the effective
     * entidad_id (persona.entidad_id, falling back to the first pivot
     * row in `entidad_persona`), then fetches the canonical name +
     * identificacion. Returns `null` if the persona is not bound to any
     * entidad — never throws.
     *
     * @return array{id: int, nombre: string, identificacion: ?string, tipo_persona: string}|null
     */
    private function resolveEntidadSnapshot(): ?array
    {
        $entidadId = $this->entidad_id !== null
            ? (int) $this->entidad_id
            : DB::table('entidad_persona')
                ->where('persona_id', (int) $this->id)
                ->orderBy('entidad_id')
                ->value('entidad_id');

        if ($entidadId === null) {
            return null;
        }

        $row = DB::table('entidad')
            ->where('id', (int) $entidadId)
            ->first(['id', 'nombre', 'identificacion', 'tipo_persona']);

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'nombre' => (string) $row->nombre,
            'identificacion' => $row->identificacion,
            'tipo_persona' => (string) $row->tipo_persona,
        ];
    }
}