<?php

namespace Modules\Shared\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rol extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'roles';

    protected $fillable = [
        'nombre',
        'slug',
        'es_super_admin',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'es_super_admin' => 'boolean',
    ];

    public function isSuperAdmin(): bool
    {
        return (bool) $this->es_super_admin;
    }
}
