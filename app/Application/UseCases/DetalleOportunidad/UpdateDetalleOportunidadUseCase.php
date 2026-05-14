<?php

namespace App\Application\UseCases\DetalleOportunidad;

use App\Application\Services\CalculoDetalleService;
use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;

class UpdateDetalleOportunidadUseCase
{
    public function __construct(
        private DetalleOportunidadRepositoryInterface $repository,
        private CalculoDetalleService $calculoService,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        if (isset($data['cantidad']) || isset($data['vr_unitario']) || isset($data['producto_id'])) {
            $existing = $this->repository->findById($id);
            if ($existing) {
                $cantidad = $data['cantidad'] ?? $existing->cantidad;
                $vrUnitario = $data['vr_unitario'] ?? $existing->vr_unitario;

                $productoId = $data['producto_id'] ?? $existing->producto_id;
                $producto = \App\Models\Producto::findOrFail($productoId);

                $calculos = $this->calculoService->calculate(
                    (float) $cantidad,
                    (float) $vrUnitario,
                    (float) $producto->iva
                );

                $data['iva'] = $calculos['iva'];
                // vr_total = (cantidad * vr_unitario) + iva
                $data['vr_total'] = $calculos['vr_total'] + $calculos['iva'];
            }
        }

        return $this->repository->update($id, $data);
    }
}
