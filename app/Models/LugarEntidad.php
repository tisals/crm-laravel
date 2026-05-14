<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LugarEntidad extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'lugares_entidad';
    protected $fillable = [
        'entidad_id',
        'area_oficina',
        'direccion',
        'direccion_adicional',
        'ciudad_cod',
        'contacto_id',
        'estado',
        'created_by',
        'updated_by',
    ];
}
