<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Domain\Repositories\OportunidadRepositoryInterface;

class ClonarOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $oportunidadRepository,
        private DetalleOportunidadRepositoryInterface $detalleRepository,
    ) {}

    public function execute(int $id): mixed
    {
        // Find source oportunidad
        $source = $this->oportunidadRepository->findById($id);
        if (!$source) {
            return null;
        }

        // Load detalles from the model directly (repository returns entity without relaciones)
        $sourceModel = \App\Models\Oportunidad::with('detalles')->find($id);
        if (!$sourceModel) {
            return null;
        }

        // Generate new codigo
        $newCodigo = $this->oportunidadRepository->getNextCodigo();

        // Create new oportunidad with same data but new codigo, estado='Borrador', fecha=today
        $newOportunidad = $this->oportunidadRepository->create([
            'codigo' => $newCodigo,
            'entidad_id' => $source->entidad_id,
            'contacto_id' => $source->contacto_id,
            'fecha' => date('Y-m-d'),
            'estado' => 'Borrador',
            'fuente_canal' => $source->fuente_canal,
            'observaciones' => $source->observaciones,
            'aclaraciones' => $source->aclaraciones,
        ]);

        if (!$newOportunidad) {
            return null;
        }

        // Clone all detalles
        foreach ($sourceModel->detalles as $detalle) {
            $this->detalleRepository->create([
                'oportunidad_id' => $newOportunidad->id,
                'producto_id' => $detalle->producto_id,
                'concepto' => $detalle->concepto,
                'medida' => $detalle->medida,
                'cantidad' => $detalle->cantidad,
                'vr_unitario' => $detalle->vr_unitario,
                'iva' => $detalle->iva,
                'vr_total' => $detalle->vr_total,
            ]);
        }

        return $newOportunidad;
    }
}
