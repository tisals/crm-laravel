<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contacto extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contacto';
    protected $fillable = [
        'entidad_id',
        'nombres',
        'apellidos',
        'area',
        'cargo',
        'tel_contacto',
        'movil',
        'email_contacto',
        'email_secundario',
        'rol',
        'etapa',
        'estado',
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

    public function entidad()
    {
        return $this->belongsTo(\App\Models\Entidad::class, 'entidad_id');
    }
}
