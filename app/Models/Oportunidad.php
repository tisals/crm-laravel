<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Oportunidad extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'oportunidad';
    protected $fillable = [
        'codigo',
        'entidad_id',
        'contacto_id',
        'fecha',
        'fuente_canal',
        'estado',
        'observaciones',
        'aclaraciones',
        'validez_oferta',
        'tiempo_entrega',
        'forma_pago',
        'garantia',
        'linea_negocio',
        'created_by',
        'updated_by',
    ];

    public function entidad()
    {
        return $this->belongsTo(Entidad::class, 'entidad_id');
    }

    public function contacto()
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleOportunidad::class, 'oportunidad_id');
    }

    public function seguimientos()
    {
        return $this->hasMany(Seguimiento::class, 'oportunidad_id');
    }
}
