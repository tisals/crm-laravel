<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Persona extends Model
{
    use HasFactory;

    protected $table = 'personas';

    protected $fillable = [
        'identificacion_tipo',
        'identificacion_numero',
        'nombres',
        'apellidos',
        'email_principal',
        'telefono_principal',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'persona_id');
    }
}
