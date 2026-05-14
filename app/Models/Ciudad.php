<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ciudad extends Model
{
    protected $table = 'ciudades';
    protected $primaryKey = 'cod_municipio';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['cod_municipio', 'nombre', 'departamento', 'created_by', 'updated_by'];
}
