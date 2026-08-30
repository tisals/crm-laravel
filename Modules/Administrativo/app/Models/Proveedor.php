<?php

namespace Modules\Administrativo\Models;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = [
        'tipo_id',
        'identificacion',
        'persona_id',
        'nombres',
        'apellidos',
        'profesion',
        'especialidad',
        'iva',
        'retenciones',
        'ciudad_cod',
        'fecha_registro',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'iva' => 'decimal:2',
            'retenciones' => 'decimal:2',
            'fecha_registro' => 'date',
        ];
    }

    /**
     * PR-E (REQ-PRRL-005): nullable FK to the persona this proveedor is.
     * Unlike colaborador, a persona can be linked to multiple proveedores
     * (different vendor roles per entity). nullOnDelete on the FK means
     * deleting the persona leaves the proveedor intact with
     * `persona_id = NULL`.
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
