<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'proveedores';
    protected $fillable = [
        'tipo_id',
        'identificacion',
        'nombres',
        'apellidos',
        'profesion',
        'especialidad',
        'iva',
        'retenciones',
        'ciudad_cod',
        'fecha_registro',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'iva' => 'decimal:2',
            'retenciones' => 'decimal:2',
            'fecha_registro' => 'date',
        ];
    }
}
