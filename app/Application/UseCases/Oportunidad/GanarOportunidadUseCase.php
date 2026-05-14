<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Domain\Repositories\ServicioRepositoryInterface;

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
        if (!$oportunidad) {
            return null;
        }

        $updated = $this->oportunidadRepository->update($id, ['estado' => 'Ganada']);

        // Update entidad estado to 'Cliente'
        \App\Models\Entidad::where('id', $oportunidad->entidad_id)
            ->update(['estado' => 'Cliente']);

        // Calculate total vr_servicio from detalles
        $sourceModel = \App\Models\Oportunidad::with('detalles')->find($id);
        $vrServicio = $sourceModel->detalles->sum('vr_total');

        // Auto-create Servicio from the won oportunidad
        $this->servicioRepository->create([
            'oportunidad_id' => $oportunidad->id,
            'entidad_id' => $oportunidad->entidad_id,
            'nombre' => 'Servicio - ' . $oportunidad->codigo,
            'vr_servicio' => $vrServicio,
            'estado' => 'Nuevo',
            'fecha_inicio' => date('Y-m-d'),
        ]);

        return $updated;
    }
}
