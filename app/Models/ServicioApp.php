<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServicioApp extends Model
{
    use HasFactory;

    protected $table = 'servicio_app';

    protected $fillable = [
        'servicio_id',
        'app_id',
        'estado',
        'fecha_activacion',
        'fecha_vencimiento',
    ];

    protected $casts = [
        'fecha_activacion' => 'date',
        'fecha_vencimiento' => 'date',
    ];
}
