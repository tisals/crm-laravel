<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DetalleServicio extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'detalle_servicios';
    protected $fillable = [
        'servicio_id',
        'producto_id',
        'observacion',
        'cantidad',
        'precio',
        'descuento',
        'sub_total',
        'iva',
        'total',
        'created_by',
        'updated_by',
    ];

    public function servicio()
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
