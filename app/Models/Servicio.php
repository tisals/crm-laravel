<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Servicio extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'servicios';
    protected $fillable = [
        'oportunidad_id',
        'entidad_id',
        'nombre',
        'vr_servicio',
        'fecha_inicio',
        'fecha_fin',
        'prestador_id',
        'estado',
        'created_by',
        'updated_by',
    ];

    public function oportunidad()
    {
        return $this->belongsTo(Oportunidad::class, 'oportunidad_id');
    }

    public function entidad()
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function prestador()
    {
        return $this->belongsTo(Proveedor::class, 'prestador_id');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleServicio::class, 'servicio_id');
    }
}
