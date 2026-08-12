<?php

namespace Modules\CRM\Models;

use App\Models\Entidad;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contacto extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contacto';

    protected $fillable = [
        'entidad_id',
        'persona_id',
        'nombres',
        'apellidos',
        'identificacion_tipo',
        'identificacion_numero',
        'area',
        'cargo',
        'tel_contacto',
        'movil',
        'email_contacto',
        'email_secundario',
        'rol',
        'etapa',
        'estado',
        'score',
        'diagnostico_data',
        'fuente',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'diagnostico_data' => 'array',
        ];
    }

    public function entidad(): BelongsTo
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
