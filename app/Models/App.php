<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class App extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'apps';

    protected $fillable = [
        'slug',
        'nombre',
        'tipo',
        'auth_type',
        'activo',
        'descripcion',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function entidades(): BelongsToMany
    {
        return $this->belongsToMany(
            Entidad::class,
            'app_entidad',
            'app_id',
            'entidad_id'
        )->withPivot(['fecha_contrato', 'fecha_vencimiento', 'estado', 'notas', 'created_by'])
          ->withTimestamps();
    }
}
