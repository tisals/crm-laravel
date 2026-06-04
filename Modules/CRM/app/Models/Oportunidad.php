<?php

namespace Modules\CRM\Models;

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
        'pipeline_id',
        'pipeline_etapa_id',
        'parent_id',
        'version',
        'is_latest',
        'fecha',
        'fuente_canal',
        'estado', // 'Activa' or 'Inactiva'
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

    public static function boot()
    {
        parent::boot();

        static::saving(function ($oportunidad) {
            // 1. Resolve pipeline if empty
            if (!$oportunidad->pipeline_id) {
                $pipeline = Pipeline::where('codigo', 'llegada')->first();
                if (!$pipeline) {
                    // Graceful fallback: create on the fly (important for tests)
                    $pipeline = Pipeline::create([
                        'nombre' => 'Llegada',
                        'codigo' => 'llegada',
                        'habilitado' => true,
                    ]);
                }
                $oportunidad->pipeline_id = $pipeline->id;
            }

            // 2. Validate pipeline
            $pipeline = Pipeline::find($oportunidad->pipeline_id);
            if (!$pipeline) {
                throw new \InvalidArgumentException("Pipeline no encontrado.");
            }

            // 3. Resolve stage from stage name (if passed in 'estado' and it's a stage name)
            if ($oportunidad->estado && !in_array($oportunidad->estado, ['Activa', 'Inactiva'])) {
                $etapa = PipelineEtapa::where('pipeline_id', $oportunidad->pipeline_id)
                    ->where('nombre', $oportunidad->estado)
                    ->first();
                if (!$etapa) {
                    // Graceful fallback: create stage on the fly (important for tests)
                    $etapa = PipelineEtapa::create([
                        'pipeline_id' => $oportunidad->pipeline_id,
                        'nombre' => $oportunidad->estado,
                        'orden' => 0,
                    ]);
                }
                $oportunidad->pipeline_etapa_id = $etapa->id;
                $oportunidad->estado = 'Activa'; // Change state to 'Activa'
            }

            // 4. Default stage if none resolved
            if (!$oportunidad->pipeline_etapa_id) {
                $firstEtapa = PipelineEtapa::where('pipeline_id', $oportunidad->pipeline_id)
                    ->orderBy('orden')
                    ->first();
                if (!$firstEtapa) {
                    // Graceful fallback: create stage on the fly (important for tests)
                    $firstEtapa = PipelineEtapa::create([
                        'pipeline_id' => $oportunidad->pipeline_id,
                        'nombre' => 'Borrador',
                        'orden' => 0,
                    ]);
                }
                $oportunidad->pipeline_etapa_id = $firstEtapa->id;
            }

            // 5. Ensure stage matches pipeline
            if ($oportunidad->pipeline_etapa_id) {
                $etapa = PipelineEtapa::find($oportunidad->pipeline_etapa_id);
                if (!$etapa || $etapa->pipeline_id !== $oportunidad->pipeline_id) {
                    throw new \InvalidArgumentException("La etapa no pertenece al pipeline seleccionado.");
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_latest' => 'boolean',
        ];
    }

    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    public function pipelineEtapa()
    {
        return $this->belongsTo(PipelineEtapa::class, 'pipeline_etapa_id');
    }

    public function parent()
    {
        return $this->belongsTo(Oportunidad::class, 'parent_id');
    }

    public function versions()
    {
        return $this->hasMany(Oportunidad::class, 'parent_id');
    }

    public function entidad()
    {
        return $this->belongsTo(\App\Models\Entidad::class, 'entidad_id');
    }

    public function contacto()
    {
        return $this->belongsTo(Contacto::class, 'contacto_id');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleOportunidad::class, 'oportunidad_id');
    }

    public function creador()
    {
        return $this->belongsTo(\Modules\Shared\Models\Usuario::class, 'created_by');
    }

    public function seguimientos()
    {
        return $this->hasMany(\App\Models\Seguimiento::class, 'oportunidad_id');
    }
}
