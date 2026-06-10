<?php

namespace Modules\CRM\Pipelines\IngestLead;

use App\Infrastructure\Persistence\EloquentOportunidadRepository;
use Closure;
use Modules\CRM\Models\Oportunidad;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\PipelineEtapa;

class CreateOportunidad
{
    public function handle(array $passable, Closure $next)
    {
        $data = $passable;
        $entidadId = $data['entidad_id'];
        $contactoId = $data['contacto_id'];

        // Check if an active latest opportunity already exists
        $oportunidad = Oportunidad::where('entidad_id', $entidadId)
            ->where('is_latest', true)
            ->where('estado', 'Activa')
            ->first();

        // If none exists, create one
        if (! $oportunidad) {
            $pipeline = Pipeline::where('codigo', 'COTIZACION')->first();
            $etapa = PipelineEtapa::where('pipeline_id', $pipeline->id)
                ->where('nombre', 'Borrador')
                ->first();

            // Resolve next sequence code GC-...
            $repo = new EloquentOportunidadRepository;
            $codigo = $repo->getNextCodigo();

            $oportunidad = Oportunidad::create([
                'codigo' => $codigo,
                'entidad_id' => $entidadId,
                'contacto_id' => $contactoId,
                'pipeline_id' => $pipeline->id,
                'pipeline_etapa_id' => $etapa->id,
                'fecha' => date('Y-m-d'),
                'estado' => 'Activa',
                'fuente_canal' => $data['fuente_canal'],
                'observaciones' => 'Ingresado automáticamente vía Pipeline de Ingesta.',
            ]);
        }

        $data['oportunidad'] = $oportunidad;

        return $next($data);
    }
}
