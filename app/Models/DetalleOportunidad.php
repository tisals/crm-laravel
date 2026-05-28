<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DetalleOportunidad extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'detalle_oportunidad';
    protected $fillable = [
        'oportunidad_id',
        'producto_id',
        'concepto',
        'descripcion',
        'medida',
        'cantidad',
        'vr_unitario',
        'iva',
        'vr_total',
        'created_by',
        'updated_by',
    ];

    public function oportunidad()
    {
        return $this->belongsTo(Oportunidad::class, 'oportunidad_id');
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class, 'producto_id');
    }
}
