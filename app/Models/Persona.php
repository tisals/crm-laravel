<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Persona extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'personas';

    /**
     * PR-A: `tipo_persona` and `entidad_id` are added so the iter4 persona
     * model can carry a Natural/Juridica classification and an optional
     * link to a parent entity.
     *
     * Commit 7a2d33c dropped `tipo_persona` (lives on `entidad` now).
     * `entidad_id` is a legacy 1:1 FK that survives only for
     * backward-compatible single-tenant lookups; the canonical
     * multi-tenant relation is `entidades()` via the `entidad_persona`
     * pivot.
     */
    protected $fillable = [
        'identificacion_tipo',
        'identificacion_numero',
        'nombres',
        'apellidos',
        'email_principal',
        'telefono_principal',
        'direccion',
        'ciudad',
        'pais',
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
}
