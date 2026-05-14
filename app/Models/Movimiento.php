<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movimiento extends Model
{
    use HasFactory;

    protected $table = 'movimientos';
    protected $fillable = [
        'fecha',
        'valor_debito',
        'valor_credito',
        'proveedor_id',
        'colaborador_id',
        'servicio_id',
        'observaciones',
        'created_by',
        'updated_by',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function colaborador()
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function servicio()
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }
}
