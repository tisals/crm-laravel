<?php

namespace App\Http\Resources;

use App\Enums\ProjectionLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Commit 6 (tenant-data-model-correction) — depth-aware projection.
 *
 *   - Shallow (depth=1): bare contacto row. NO `entidad_id` lookup
 *     against the `entidad_persona` pivot, NO `entidad_nombre` accessor
 *     (which would lazy-load the linked Entidad model). List endpoints
 *     consume this.
 *
 *   - Default (depth=2): identity + the `entidad_id` (resolved from
 *     `entidad_persona` pivot per commit fe99f70) + `entidad_nombre`
 *     (from the eager-loaded `entidad` relation if present, else a
 *     fallback single-row join). Canonical detail shape.
 *
 *   - Deep (depth=3): + the linked `persona` object (with its
 *     `email_principal` / `telefono_principal` primaries) + a nested
 *     `entidad` snapshot. Webhook snapshots use this.
 *
 * Per commit fe99f70: `contacto.entidad_id` was dropped. The contacto's
 * entidad binding lives in the `entidad_persona` pivot (via the
 * underlying persona). We expose the FIRST pivot's `entidad_id` as the
 * legacy `entidad_id` field for API consumers that still depend on it.
 */
class ContactoResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        $level = $this->depth($request);
        $isShallow = $level === ProjectionLevel::Shallow;
        $isDeep = $level === ProjectionLevel::Deep;

        $base = [
            'id' => $this->id,
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
            'diagnostico_data' => $this->diagnostico_data ?? null,
            'fuente' => $this->fuente ?? null,
        ];

        if ($isShallow) {
            return $base;
        }

        // entidad binding via pivot (legacy field name kept for API
        // stability — see commit fe99f70). Prefer the value the entity
        // already computed (the repository usually resolves it from the
        // pivot on read); fall back to a fresh pivot lookup when the
        // entity carries only `persona_id` (some legacy read paths).
        $entidadId = $this->entidad_id ?? null;
        if ($entidadId === null && $this->persona_id !== null) {
            $entidadId = DB::table('entidad_persona')
                ->where('persona_id', $this->persona_id)
                ->orderBy('entidad_id')
                ->value('entidad_id');
        }
        $base['entidad_id'] = $entidadId !== null ? (int) $entidadId : null;

        // entidad_nombre: prefer (1) the eager-loaded relation (no extra
        // roundtrip), then (2) the entity's pre-computed accessor, then
        // (3) a fresh single-row join when the resource is built around
        // a domain entity that doesn't carry the resolved name.
        if ($this->isRelationLoaded('entidad') && $this->entidad) {
            $base['entidad_nombre'] = $this->entidad->nombre;
        } elseif (isset($this->entidad_nombre) && $this->entidad_nombre !== null) {
            $base['entidad_nombre'] = $this->entidad_nombre;
        } elseif ($base['entidad_id'] !== null) {
            $base['entidad_nombre'] = DB::table('entidad')
                ->where('id', $base['entidad_id'])
                ->value('nombre');
        } else {
            $base['entidad_nombre'] = null;
        }

        // Deep: surface the linked persona + entidad as nested objects
        // so the snapshot consumer has the full contact context without
        // a second roundtrip.
        if ($isDeep) {
            if ($this->persona_id) {
                // Commit 5 dropped `personas.tipo_persona` (it lives on
                // `entidad` now). The accessor on the Persona model
                // surfaces it, but a raw `personas` row only carries
                // the identity columns.
                $personaRow = $this->isRelationLoaded('persona') && $this->persona
                    ? $this->persona
                    : DB::table('personas')->where('id', (int) $this->persona_id)->first(['id', 'nombres', 'apellidos']);

                if ($personaRow) {
                    $base['persona'] = [
                        'id' => (int) $personaRow->id,
                        'nombres' => (string) $personaRow->nombres,
                        'apellidos' => $personaRow->apellidos,
                        // `tipo_persona` is read from the linked entidad
                        // (post-Commit 5). Fall back to 'Natural' if
                        // the persona isn't entidad-bound (legacy).
                        'tipo_persona' => $this->resolvePersonaTipo($personaRow),
                        'email_principal' => DB::table('emails')
                            ->where('persona_id', (int) $personaRow->id)
                            ->where('es_principal', true)
                            ->value('email'),
                    ];
                }
            }

            if ($base['entidad_id'] !== null) {
                $entidadRow = $this->isRelationLoaded('entidad') && $this->entidad
                    ? $this->entidad
                    : DB::table('entidad')->where('id', $base['entidad_id'])->first(['id', 'nombre', 'identificacion', 'tipo_persona']);

                if ($entidadRow) {
                    $base['entidad'] = [
                        'id' => (int) $entidadRow->id,
                        'nombre' => (string) $entidadRow->nombre,
                        'identificacion' => $entidadRow->identificacion,
                        'tipo_persona' => (string) $entidadRow->tipo_persona,
                    ];
                }
            }
        }

        return $base;
    }

    /**
     * Resolve the persona's `tipo_persona` for the deep snapshot. Reads
     * from the linked entidad (post-Commit 5 the column moved off
     * `personas`), defaulting to 'Natural' for unbound personas.
     *
     * @param  object{id: int, nombres: string, apellidos: ?string}|\Modules\Administrativo\Models\Persona  $personaRow
     */
    private function resolvePersonaTipo(object $personaRow): string
    {
        $entidadId = DB::table('entidad_persona')
            ->where('persona_id', (int) $personaRow->id)
            ->orderBy('entidad_id')
            ->value('entidad_id');

        if ($entidadId === null) {
            return 'Natural';
        }

        return (string) (DB::table('entidad')->where('id', (int) $entidadId)->value('tipo_persona') ?? 'Natural');
    }
}