<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Commit 5 — `entidad_relacion` pivot.
 *
 * Records the business-state relation between this project and the
 * entidad over time. Each row is a (entidad, tipo_relacion,
 * effective_from, effective_to) tuple; the temporal model lets
 * us reconstruct how the entity's business relationship evolved
 * (was a prospect, became a cliente, etc.).
 *
 * The pivot is the canonical source of truth for `cliente` /
 * `prospecto` / `propia` / `proveedor` going forward. The legacy
 * `entidad.estado` column stays in place (Commit 5 keeps it
 * deprecated per the option-2 contract) for one migration cycle.
 */
class EntidadRelacion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'entidad_relacion';

    protected $fillable = [
        'entidad_id',
        'tipo_relacion',
        'effective_from',
        'effective_to',
        'frecuencia',
        'recurrencia_cada_meses',
        'vigencia_meses',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'recurrencia_cada_meses' => 'integer',
            'vigencia_meses' => 'integer',
        ];
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by');
    }
}
