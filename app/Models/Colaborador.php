<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Colaborador extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'colaboradores';
    protected $fillable = [
        'usuario_id',
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
}
