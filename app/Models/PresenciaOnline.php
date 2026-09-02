<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Commit 3 — `presencia_online` table.
 *
 * Unifies the legacy `entidad.dominio` (tipo=web, plataforma=otro)
 * and `entidad.red_social_url` (tipo=red_social, plataforma from URL
 * host heuristic) into a single typed table.
 */
class PresenciaOnline extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'presencia_online';

    protected $fillable = [
        'persona_id',
        'entidad_id',
        'tipo',
        'plataforma',
        'handle',
        'url',
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
