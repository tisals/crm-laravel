<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Permiso extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'permisos';
    protected $fillable = ['rol_id', 'vista', 'created_by', 'updated_by'];

    public function rol()
    {
        return $this->belongsTo(Rol::class);
    }
}
