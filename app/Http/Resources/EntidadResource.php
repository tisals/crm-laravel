<?php

namespace App\Http\Resources;

use App\Enums\ProjectionLevel;
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
 *
 * Commit 6 (tenant-data-model-correction) — depth-aware projection.
 *
 *   - Shallow (depth=1): bare identity. NO `direccion`, `email`,
 *     `telefono`, `dominio`, `ciudad_cod` (no DB lookups), NO nested
 *     collections. List endpoints consume this.
 *
 *   - Default (depth=2): identity + the four primary-row fields
 *     (resolved from the shared contact tables) + the model-only
 *     fields (`cantidad_empleados`, `rut`, `logo`, `estado`).
 *     Canonical detail shape, identical to the pre-Commit-6 envelope
 *     plus the principal-row fields the model never exposed.
 *
 *   - Deep (depth=3): + nested collections — `direcciones`, `emails`,
 *     `telefonos`, `presenciaOnline`, `documentos`, `relaciones` —
 *     for the Mercury mirror and webhook snapshots. These are full
 *     second-degree relations the caller would otherwise have to
 *     fetch one-by-one.
 *
 * Each `principalX()` helper does a single `WHERE entity_id = ? AND
 * es_principal = true` lookup. The `direcciones.ciudad_codigo` is
 * still surfaced as `ciudad_cod` for backwards compatibility.
 */
class EntidadResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        $level = $this->depth($request);
        $isShallow = $level === ProjectionLevel::Shallow;
        $isDeep = $level === ProjectionLevel::Deep;
        $entidadId = (int) $this->id;

        $base = [
            'id' => $this->id,
            'tipo_persona' => $this->tipo_persona,
            'tipo_id' => $this->tipo_id,
            'identificacion' => $this->identificacion,
            'nombre' => $this->nombre,
            'nombre_comercial' => $this->nombre_comercial,
        ];

        // The contact-row lookups + nested collections are only paid for
        // at depth ≥ 2. Shallow projection stops here.
        if ($isShallow) {
            return $base;
        }

        $base['direccion'] = $this->principalDireccion($entidadId);
        $base['ciudad_cod'] = $this->principalDireccionCod($entidadId);
        $base['dominio'] = $this->principalDominio($entidadId);
        $base['email'] = $this->principalEmailTrabajo($entidadId);
        $base['telefono'] = $this->principalTelefonoTrabajo($entidadId);

        // Fields that live on the Eloquent Entidad model but NOT on the
        // domain entity. `prop()` reads defensively so the resource can
        // be built around either shape without dynamic-property warnings.
        $base['cantidad_empleados'] = $this->prop('cantidad_empleados');
        $base['rut'] = $this->prop('rut');
        $base['logo'] = $this->prop('logo');
        $base['estado'] = $this->prop('estado') ?? 'Activo';
        $base['created_by'] = $this->prop('created_by');
        $base['updated_by'] = $this->prop('updated_by');
        $base['created_at'] = $this->prop('created_at');
        $base['updated_at'] = $this->prop('updated_at');

        // Backwards-compat fields that live ONLY on the domain entity
        // (counts + denormalized ciudad_nombre + comercial_asignado).
        $base['contactos_count'] = $this->prop('contactos_count');
        $base['oportunidades_count'] = $this->prop('oportunidades_count');
        $base['comercial_asignado'] = $this->prop('comercial_asignado');
        $base['ciudad_nombre'] = $this->prop('ciudad_nombre');

        // Deep projection: surface the shared contact-table collections
        // as nested arrays. Each collection is queried with one roundtrip.
        if ($isDeep) {
            $base['direcciones'] = DB::table('direcciones')
                ->where('entidad_id', $entidadId)
                ->orderByDesc('id')
                ->get(['id', 'direccion_principal', 'nombre_sede', 'ciudad_codigo', 'pais', 'es_principal'])
                ->map(fn ($r) => (array) $r)
                ->all();

            $base['emails'] = DB::table('emails')
                ->where('entidad_id', $entidadId)
                ->orderByDesc('id')
                ->get(['id', 'email', 'tipo', 'es_principal'])
                ->map(fn ($r) => (array) $r)
                ->all();

            $base['telefonos'] = DB::table('telefonos')
                ->where('entidad_id', $entidadId)
                ->orderByDesc('id')
                ->get(['id', 'numero', 'tipo', 'es_principal'])
                ->map(fn ($r) => (array) $r)
                ->all();

            $base['presenciaOnline'] = DB::table('presencia_online')
                ->where('entidad_id', $entidadId)
                ->orderByDesc('id')
                ->get(['id', 'tipo', 'url', 'es_principal'])
                ->map(fn ($r) => (array) $r)
                ->all();

            $base['documentos'] = DB::table('documentos')
                ->where('entidad_id', $entidadId)
                ->orderByDesc('id')
                ->get(['id', 'tipo_documento', 'nombre_archivo', 'path_storage', 'created_at'])
                ->map(fn ($r) => (array) $r)
                ->all();

            // `entidad_relacion` is the pivot where the canonical
            // business state (active/cliente/etc) now lives (per
            // Commit 5.5). Surface the tipo_relacion + effective window.
            $base['relaciones'] = DB::table('entidad_relacion')
                ->where('entidad_id', $entidadId)
                ->orderByDesc('id')
                ->get(['id', 'tipo_relacion', 'effective_from', 'effective_to'])
                ->map(fn ($r) => (array) $r)
                ->all();
        }

        return $base;
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