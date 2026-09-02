<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Commit 3 — `direcciones` table.
 *
 * `ciudad_codigo` is a FK to `ciudades.cod_municipio` (the ciudades
 * PK; there's no `id` column). `nombre_sede` is the slot entidades
 * use to label multi-location branches ("Sucursal Norte",
 * "Sucursal Centro").
 */
class Direccion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'direcciones';

    protected $fillable = [
        'persona_id',
        'entidad_id',
        'ciudad_codigo',
        'direccion_principal',
        'direccion_complementaria',
        'nombre_sede',
        'codigo_postal',
        'pais',
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

    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class, 'ciudad_codigo', 'cod_municipio');
    }
}
