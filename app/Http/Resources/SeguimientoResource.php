<?php

namespace App\Http\Resources;

use App\Enums\ProjectionLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Commit 6 (tenant-data-model-correction) — depth-aware projection.
 *
 *   - Shallow (depth=1): bare row. The four `*_nombre` / `*_codigo`
 *     accessors (`entidad_nombre`, `contacto_nombre`,
 *     `oportunidad_codigo`, `autor_nombre`) are skipped — they would
 *     trigger lazy loads of the linked rows, which is the whole point
 *     of the shallow path.
 *
 *   - Default (depth=2): the canonical detail shape. The `*_nombre`
 *     accessors are honoured (the model has them via appends, see
 *     `Modules\CRM\app/Models/Seguimiento.php`); if the resource is
 *     instantiated with a domain entity instead, we resolve them via
 *     a single join query each so the response stays consistent.
 *
 *   - Deep (depth=3): + nested `persona` and `oportunidad` objects
 *     (full second-degree relations), and the linked
 *     `oportunidad.detalles[]` collection. Webhook snapshots use this.
 *
 * PR-H (Phase 5b - REQ-SEG-004): `persona_id` replaces the legacy
 * `contacto_id` (FK swap). The `contacto_nombre` accessor keeps the
 * JSON key for backward compatibility with the dashboard, but resolves
 * from `persona.nombres + persona.apellidos`.
 */
class SeguimientoResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        $level = $this->depth($request);
        $isShallow = $level === ProjectionLevel::Shallow;
        $isDeep = $level === ProjectionLevel::Deep;

        $base = [
            'id' => $this->id,
            'oportunidad_id' => $this->oportunidad_id,
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
        ];

        if ($isShallow) {
            return $base;
        }

        // The four `*_nombre` / `*_codigo` accessors exist on the
        // Eloquent model (via `protected $appends`) but NOT on the
        // domain entity. Try them first; if the resource was built
        // around a domain entity, fall back to single-row joins.
        $base['entidad_nombre'] = $this->resolvedEntidadNombre();
        $base['contacto_nombre'] = $this->resolvedContactoNombre();
        $base['oportunidad_codigo'] = $this->resolvedOportunidadCodigo();
        $base['autor_nombre'] = $this->resolvedAutorNombre();

        // Deep: surface the linked persona and oportunidad as nested
        // objects so the snapshot consumer doesn't have to roundtrip.
        if ($isDeep) {
            $base['persona'] = $this->resolvedPersonaSnapshot();
            $base['oportunidad'] = $this->resolvedOportunidadSnapshot();
        }

        return $base;
    }

    /**
     * Resolve `entidad_nombre` from either the model's eager-loaded
     * `entidad` relation or a fallback single-row join. Returns null
     * for orphan seguimientos.
     */
    private function resolvedEntidadNombre(): ?string
    {
        if ($this->isRelationLoaded('entidad') && $this->entidad) {
            return $this->entidad->nombre;
        }

        if (! $this->entidad_id) {
            return null;
        }

        return DB::table('entidad')->where('id', (int) $this->entidad_id)->value('nombre');
    }

    /**
     * Resolve `contacto_nombre` from `persona.nombres + persona.apellidos`
     * (post-PR-G canonical identity axis). The label is kept as
     * `contacto_nombre` for backward compatibility with the dashboard.
     */
    private function resolvedContactoNombre(): ?string
    {
        if ($this->isRelationLoaded('persona') && $this->persona) {
            return trim("{$this->persona->nombres} {$this->persona->apellidos}");
        }

        if (! $this->persona_id) {
            return null;
        }

        $row = DB::table('personas')->where('id', (int) $this->persona_id)->first(['nombres', 'apellidos']);

        return $row ? trim("{$row->nombres} {$row->apellidos}") : null;
    }

    private function resolvedOportunidadCodigo(): ?string
    {
        if ($this->isRelationLoaded('oportunidad') && $this->oportunidad) {
            return $this->oportunidad->codigo;
        }

        if (! $this->oportunidad_id) {
            return null;
        }

        return DB::table('oportunidad')->where('id', (int) $this->oportunidad_id)->value('codigo');
    }

    private function resolvedAutorNombre(): ?string
    {
        if ($this->isRelationLoaded('autor') && $this->autor) {
            return $this->autor->nombre;
        }

        if (! $this->autor_id) {
            return null;
        }

        return DB::table('usuarios')->where('id', (int) $this->autor_id)->value('nombre');
    }

    /**
     * Flat persona snapshot for deep projection. Returns `null` if the
     * seguimiento isn't linked to a persona.
     *
     * @return array{id: int, nombres: string, apellidos: ?string, email_principal: ?string}|null
     */
    private function resolvedPersonaSnapshot(): ?array
    {
        if (! $this->persona_id) {
            return null;
        }

        if ($this->isRelationLoaded('persona') && $this->persona) {
            $p = $this->persona;

            return [
                'id' => (int) $p->id,
                'nombres' => (string) $p->nombres,
                'apellidos' => $p->apellidos,
                'email_principal' => DB::table('emails')
                    ->where('persona_id', (int) $p->id)
                    ->where('es_principal', true)
                    ->value('email'),
            ];
        }

        $row = DB::table('personas')->where('id', (int) $this->persona_id)->first(['id', 'nombres', 'apellidos']);

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'nombres' => (string) $row->nombres,
            'apellidos' => $row->apellidos,
            'email_principal' => DB::table('emails')
                ->where('persona_id', (int) $row->id)
                ->where('es_principal', true)
                ->value('email'),
        ];
    }

    /**
     * Flat oportunidad snapshot for deep projection. Includes the
     * nested `detalles[]` collection so the snapshot carries the full
     * scope of the parent deal.
     *
     * @return array{id: int, codigo: string, estado: string, valor: float|int|null, detalles: array<int, array<string, mixed>>}|null
     */
    private function resolvedOportunidadSnapshot(): ?array
    {
        if (! $this->oportunidad_id) {
            return null;
        }

        if ($this->isRelationLoaded('oportunidad') && $this->oportunidad) {
            $o = $this->oportunidad;

            return [
                'id' => (int) $o->id,
                'codigo' => (string) $o->codigo,
                'estado' => (string) $o->estado,
                'valor' => DB::table('detalle_oportunidad')
                    ->where('oportunidad_id', (int) $o->id)
                    ->sum('vr_total'),
                'detalles' => DB::table('detalle_oportunidad')
                    ->where('oportunidad_id', (int) $o->id)
                    ->orderBy('id')
                    ->get(['id', 'producto_id', 'concepto', 'cantidad', 'vr_unitario', 'iva', 'vr_total'])
                    ->map(fn ($r) => (array) $r)
                    ->all(),
            ];
        }

        $row = DB::table('oportunidad')->where('id', (int) $this->oportunidad_id)->first(['id', 'codigo', 'estado']);

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'codigo' => (string) $row->codigo,
            'estado' => (string) $row->estado,
            'valor' => DB::table('detalle_oportunidad')
                ->where('oportunidad_id', (int) $row->id)
                ->sum('vr_total'),
            'detalles' => DB::table('detalle_oportunidad')
                ->where('oportunidad_id', (int) $row->id)
                ->orderBy('id')
                ->get(['id', 'producto_id', 'concepto', 'cantidad', 'vr_unitario', 'iva', 'vr_total'])
                ->map(fn ($r) => (array) $r)
                ->all(),
        ];
    }
}