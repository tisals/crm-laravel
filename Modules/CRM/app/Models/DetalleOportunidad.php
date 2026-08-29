<?php

namespace Modules\CRM\Models;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DetalleOportunidad extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'detalle_oportunidad';

    /**
     * Documented offer types (REQ-DOP-002).
     *
     * Keep in sync with `DetalleOportunidadRequest::rules()` and the OpenAPI
     * schema. The Form Request enforces this allow-list on the API edge;
     * the DB column is VARCHAR(50) NULL to accept legacy + new types during
     * the migration window.
     */
    public const TIPOS_OFERTA = [
        'servicio',
        'producto',
        'curso',
        'oto',
        'bump',
        'cross-sell',
        'down-sell',
        'otro',
    ];

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
        'tipo_oferta',
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
