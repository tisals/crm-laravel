<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Commit 3 — `telefonos` table.
 *
 * Entity-or-person: at least one of `persona_id` / `entidad_id`
 * must be set (enforced by a BEFORE INSERT/UPDATE trigger in the
 * migration). The model accepts either, and the relation back to the
 * parent (Persona or Entidad) is exposed via `persona()` / `entidad()`.
 */
class Telefono extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'telefonos';

    protected $fillable = [
        'persona_id',
        'entidad_id',
        'numero',
        'indicativo',
        'tipo',
        'es_principal',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'es_principal' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }
}
