<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActividadLog extends Model
{
    protected $table = 'actividad_log';

    protected $fillable = [
        'usuario_id',
        'tipo',
        'descripcion',
        'modelo_type',
        'modelo_id',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
