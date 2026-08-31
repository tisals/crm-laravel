<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Persona extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'personas';

    /**
     * PR-A: `tipo_persona` and `entidad_id` are added so the iter4 persona
     * model can carry a Natural/Juridica classification and an optional
     * link to a parent entity.
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
        'tipo_persona',
        'entidad_id',
    ];

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }

    /**
     * PR-A: each persona optionally belongs to an entidad. nullOnDelete on
     * the FK constraint means deleting the entidad leaves the persona row
     * with `entidad_id = NULL` (audit trail preserved).
     */
    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }
}
