<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Domain\Repositories\ServicioRepositoryInterface;
use App\Models\Entidad;
use App\Models\Oportunidad;
use Illuminate\Support\Facades\DB;

class GanarOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $oportunidadRepository,
        private ServicioRepositoryInterface $servicioRepository,
        private DetalleOportunidadRepositoryInterface $detalleRepository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        $oportunidad = $this->oportunidadRepository->findById($id);
        if (! $oportunidad) {
            return null;
        }

        // Resolve the canonical ACEPTADA stage by codigo (stable identifier).
        $aceptadaId = DB::table('pipeline_etapas')
            ->where('codigo', 'ACEPTADA')
            ->value('id');

        if (! $aceptadaId) {
            throw new \RuntimeException('Pipeline stage ACEPTADA not found. Run migrations.');
        }

        // Move the oportunidad to the ACEPTADA stage (last positive stage in the
        // Cotización pipeline). The legacy `estado = 'Ganada'` flag is preserved
        // as a separate logical marker that this op was explicitly marked as won
        // (it has an associated Servicio).
        $updated = $this->oportunidadRepository->update($id, [
            'pipeline_etapa_id' => $aceptadaId,
            'estado' => 'Ganada',
        ]);

        // Update entidad to cliente business-state. Commit 5.5
        // removed `entidad.estado` (now derived from `entidad_relacion`)
        // and `entidad.cliente_desde` (now the pivot's `effective_from`
        // on the open `cliente` row). `markAsCliente()` preserves the
        // "first-win date doesn't move" semantics.
        $entidad = Entidad::find($oportunidad->entidad_id);
        $entidad?->markAsCliente();

        // Calculate total vr_servicio from detalles
        $sourceModel = Oportunidad::with('detalles')->find($id);
        $vrServicio = $sourceModel->detalles->sum('vr_total');

        // Auto-create Servicio from the won oportunidad
        $this->servicioRepository->create([
            'oportunidad_id' => $oportunidad->id,
            'entidad_id' => $oportunidad->entidad_id,
            'nombre' => 'Servicio - '.$oportunidad->codigo,
            'vr_servicio' => $vrServicio,
            'estado' => 'Nuevo',
            'fecha_inicio' => date('Y-m-d'),
        ]);

        return $updated;
    }
}
