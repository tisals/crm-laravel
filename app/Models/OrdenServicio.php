<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrdenServicio extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'orden_servicio';
    protected $fillable = [
        'detalle_srv_id',
        'colaborador_id',
        'proveedor_id',
        'contacto_id',
        'descripcion',
        'objetivo',
        'ubicacion',
        'fecha_desde',
        'fecha_hasta',
        'valor',
        'estado',
        'created_by',
        'updated_by',
    ];

    public function detalleServicio()
    {
        return $this->belongsTo(DetalleServicio::class, 'detalle_srv_id');
    }

    public function colaborador()
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function contacto()
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }
}
