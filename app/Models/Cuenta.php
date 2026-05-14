<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cuenta extends Model
{
    use HasFactory;
    // No SoftDeletes — cuentas bancarias son registros permanentes

    protected $table = 'cuentas';
    protected $fillable = [
        'proveedor_id',
        'banco',
        'numero_cuenta',
        'tipo',
        'estado',
        'created_by',
        'updated_by',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }
}
