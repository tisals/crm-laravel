<?php

namespace Modules\CRM\Models;

use App\Models\Entidad;
use Database\Factories\SeguimientoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Canonical Eloquent model for `seguimiento`.
 *
 * Use this class directly in new code. The legacy alias `App\Models\Seguimiento`
 * extends this one for backwards compatibility during the modular-migration.
 */
class Seguimiento extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'seguimiento';

    protected $fillable = [
        'oportunidad_id',
        'contacto_id',
        'entidad_id',
        'tipo',
        'fecha',
        'hora',
        'fecha_fin',
        'notas',
        'autor_id',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_fin' => 'datetime',
        'hora' => 'string',
    ];

    protected static function newFactory(): SeguimientoFactory
    {
        return SeguimientoFactory::new();
    }

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(Oportunidad::class, 'oportunidad_id');
    }

    public function contacto(): BelongsTo
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(\Modules\Shared\Models\Usuario::class, 'autor_id');
    }
}
