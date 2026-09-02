<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Commit 3 — `emails` table.
 */
class Email extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emails';

    protected $fillable = [
        'persona_id',
        'entidad_id',
        'email',
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
