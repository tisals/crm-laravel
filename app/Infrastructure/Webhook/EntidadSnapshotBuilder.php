<?php

namespace App\Infrastructure\Webhook;

use App\Models\Entidad;
use Illuminate\Support\Facades\DB;

/**
 * Commit 7 — Builds the receiver-facing snapshot for `EntidadChanged`
 * events (Consumed by `EntidadesSnapshotEmitter`).
 *
 * The shape is locked by Mercurio's CQRS mirror
 * (`mercurio_entidades_snapshot`):
 *
 *   - `id`                       — primary key
 *   - `nombre`                   — display name (no `slug` column on
 *                                  `entidad`, so `nombre` doubles as the
 *                                  human-readable identifier Mercurio
 *                                  indexes on)
 *   - `nombre_comercial`         — trade name
 *   - `tipo_persona`             — 'Natural' | 'Juridica' (ENUM)
 *   - `identificacion`           — tax ID / national ID
 *   - `email_principal`          — from `emails.tipo='trabajo' AND es_principal=true`
 *   - `telefono_principal`       — from `telefonos.tipo='trabajo' AND es_principal=true`
 *   - `direccion_principal`      — from `direcciones.es_principal=true`
 *   - `dominio`                  — from `presencia_online.tipo='web' AND es_principal=true`
 *   - `is_active`                — derived from open pivot rows
 *                                  (`entidad.estado` was dropped in Commit
 *                                  5.5; we compute the bool via
 *                                  `Entidad::getEstadoAttribute()` so the
 *                                  rule lives in exactly one place)
 *   - `relaciones_count`         — total pivot rows
 *   - `contactos_count`          — linked contactos
 *   - `oportunidades_count`      — linked oportunidades
 *   - `usuarios_count`           — linked auth credentials
 *   - `deleted_at`               — ISO-8601 string on deleted events
 *
 * The builder is decoupled from the listener / use case so the same
 * shape can be reused by tests, the Mercurio one-shot backfill script
 * (when we get to it), or any future admin endpoint that needs to
 * project an entity for an external system.
 */
class EntidadSnapshotBuilder
{
    /**
     * Build the snapshot for the entity with the given id.
     *
     * Returns null when the entity has been hard-deleted (the caller
     * — `DestroyEntidadUseCase` — already captured the pre-delete
     * shape). For soft-deleted entities the row is still queryable and
     * we surface `deleted_at` on the snapshot.
     */
    public function buildForEntidadId(int $entidadId, ?string $deletedAtOverride = null): ?array
    {
        $row = DB::table('entidad')->where('id', $entidadId)->first();

        if (! $row) {
            return null;
        }

        return $this->project($row, $deletedAtOverride);
    }

    /**
     * Build the snapshot from an Eloquent `Entidad` model (already
     * loaded). Used by the use cases that already pulled the model out
     * of the repository — saves one roundtrip.
     */
    public function buildForModel(Entidad $entidad, ?string $deletedAtOverride = null): array
    {
        $row = (object) $entidad->getAttributes();

        return $this->project($row, $deletedAtOverride);
    }

    /**
     * Project a raw entidad row to the receiver-facing snapshot.
     *
     * @param  object  $row  Raw `entidad` row (`stdClass` from `DB::table`)
     *                       OR cast-to-stdClass Eloquent attributes.
     */
    private function project(object $row, ?string $deletedAtOverride): array
    {
        $entidadId = (int) $row->id;
        $deletedAt = $deletedAtOverride
            ?? (isset($row->deleted_at) && $row->deleted_at !== null ? (string) $row->deleted_at : null);

        return [
            'id' => $entidadId,
            'nombre' => $row->nombre,
            'nombre_comercial' => $row->nombre_comercial ?? null,
            'tipo_persona' => $row->tipo_persona ?? 'Natural',
            'identificacion' => $row->identificacion ?? null,

            // Principal-row lookups — each is a single indexed query.
            // Mirrors `EntidadResource::principalX()` semantics.
            'email_principal' => $this->principalEmailTrabajo($entidadId),
            'telefono_principal' => $this->principalTelefonoTrabajo($entidadId),
            'direccion_principal' => $this->principalDireccion($entidadId),
            'dominio' => $this->principalDominio($entidadId),

            // Derived `is_active` — the legacy `entidad.estado` column was
            // dropped by Commit 5.5; we re-derive the bool from the open
            // pivot row. Inline query so we don't have to instantiate the
            // Eloquent model here (the builder is pure data projection).
            'is_active' => $this->isActive($entidadId),

            // Counts — single SELECT per relation. The repository's
            // withCount path is not reachable from here because we work
            // off a raw row, so the queries are explicit.
            'relaciones_count' => (int) DB::table('entidad_relacion')
                ->where('entidad_id', $entidadId)
                ->count(),
            'contactos_count' => (int) DB::table('personas')
                ->join('contacto', 'contacto.persona_id', '=', 'personas.id')
                ->where('personas.entidad_id', $entidadId)
                ->count(),
            'oportunidades_count' => (int) DB::table('oportunidad')
                ->where('entidad_id', $entidadId)
                ->count(),
            'usuarios_count' => (int) DB::table('entidad_persona')
                ->join('usuarios', 'usuarios.persona_id', '=', 'entidad_persona.persona_id')
                ->where('entidad_persona.entidad_id', $entidadId)
                ->count(),

            // Stamp `deleted_at` only when present so the receiver can
            // differentiate a live row from a tombstone without re-querying.
            'deleted_at' => $deletedAt,
        ];
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

    private function principalDireccion(int $entidadId): ?string
    {
        return DB::table('direcciones')
            ->where('entidad_id', $entidadId)
            ->where('es_principal', true)
            ->orderByDesc('id')
            ->value('direccion_principal');
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

    /**
     * Mirror `Entidad::getEstadoAttribute()` (`'activo'` ⇔ has open pivot
     * row). Returns `bool` because the wire contract is bool, not the
     * accessor string. Keeping the rule here as inline SQL avoids a
     * model-instantiation roundtrip on the hot path.
     */
    private function isActive(int $entidadId): bool
    {
        return DB::table('entidad_relacion')
            ->where('entidad_id', $entidadId)
            ->whereNull('effective_to')
            ->exists();
    }
}