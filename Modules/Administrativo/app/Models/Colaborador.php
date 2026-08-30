<?php

namespace Modules\Administrativo\Models;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Colaborador extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'colaboradores';

    protected $fillable = [
        'usuario_id',
        'persona_id',
        'nombres',
        'apellidos',
        'tipo_id',
        'identificacion',
        'cargo',
        'area',
        'fecha_ingreso',
        'fecha_retiro',
        'contrato',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'fecha_retiro' => 'date',
        ];
    }

    /**
     * PR-E (REQ-PRRL-005, RQ-1): UNIQUE FK to the persona this
     * colaborador is. A collaborator is conceptually a single individual,
     * matching the existing `colaboradores.identificacion` UNIQUE
     * invariant. nullOnDelete on the FK means deleting the persona
     * leaves the colaborador intact with `persona_id = NULL`.
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
