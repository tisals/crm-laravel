<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Persona extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'personas';

    /**
     * PR-A added `tipo_persona` + `entidad_id` for the iter4 persona
     * model. Commit 7a2d33c dropped `tipo_persona` (it lives on
     * `entidad` now). `entidad_id` is a legacy 1:1 FK that survives
     * for backward-compatible single-tenant lookups; the canonical
     * multi-tenant relation is `entidades()` via the `entidad_persona`
     * pivot.
     *
     * Commit 4 dropped the contact-data columns that Commit 3 backfilled
     * to `emails` / `telefonos` / `direcciones`. Callers that need to
     * read a persona's email now go through `$persona->emails()->first()`
     * (or the email rows' `principal` flag), not a column on this model.
     */
    protected $fillable = [
        'identificacion_tipo',
        'identificacion_numero',
        'nombres',
        'apellidos',
        'entidad_id',
    ];

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    /**
     * PR-A (legacy): each persona optionally belongs to an entidad.
     * nullOnDelete on the FK constraint means deleting the entidad
     * leaves the persona row with `entidad_id = NULL` (audit trail
     * preserved). Kept for backward compatibility with single-tenant
     * callers that read `$persona->entidad`; the canonical multi-tenant
     * relation is `entidades()` below.
     */
    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    /**
     * The entidades this persona is bound to via the `entidad_persona`
     * pivot. This is the canonical multi-tenant relation going forward
     * (replaces the legacy `entidad_id` FK for cross-tenant lookups).
     *
     * The pivot row carries `categoria` (`dependencia` / `asignacion`
     * / `delegacion`) so callers can filter the relation by role.
     */
    public function entidades(): BelongsToMany
    {
        return $this->belongsToMany(
            Entidad::class,
            'entidad_persona',
            'persona_id',
            'entidad_id'
        )->withPivot('categoria');
    }

    // ── Commit 3 shared contact relations ──────────────────────────────

    /**
     * All telefonos rows that belong to this persona. The relation
     * also covers rows that have BOTH persona_id and entidad_id set
     * (a contacto bound to a persona who is also a user of an entidad);
     * in that case the row will surface under BOTH the persona's
     * `telefonos()` relation and the entidad's `telefonos()` relation.
     */
    public function telefonos(): HasMany
    {
        return $this->hasMany(\App\Models\Telefono::class, 'persona_id');
    }

    /**
     * All emails rows for this persona. See `telefonos()` for the
     * both-side semantics.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(\App\Models\Email::class, 'persona_id');
    }

    /**
     * All direcciones rows for this persona. See `telefonos()` for
     * the both-side semantics.
     */
    public function direcciones(): HasMany
    {
        return $this->hasMany(\App\Models\Direccion::class, 'persona_id');
    }

    /**
     * All presencia_online rows for this persona. See `telefonos()`
     * for the both-side semantics.
     */
    public function presenciaOnline(): HasMany
    {
        return $this->hasMany(\App\Models\PresenciaOnline::class, 'persona_id');
    }

    /**
     * All documentos rows for this persona. See `telefonos()` for
     * the both-side semantics.
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(\App\Models\Documento::class, 'persona_id');
    }
}
