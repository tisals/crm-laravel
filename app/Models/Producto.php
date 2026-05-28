<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'productos';
    protected $fillable = [
        'nombre',
        'linea_negocio',
        'referencia',
        'iva',
        'medida',
        'vr_unitario',
        'estado',
        'created_by',
        'updated_by',
        'tipo',
        'descripcion',
        'caracteristicas',
    ];

    protected function casts(): array
    {
        return [
            'caracteristicas' => 'array',
        ];
    }
}
