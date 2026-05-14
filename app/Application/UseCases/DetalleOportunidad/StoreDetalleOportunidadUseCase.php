<?php

namespace App\Application\UseCases\DetalleOportunidad;

use App\Application\Services\CalculoDetalleService;
use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Models\Producto;

class StoreDetalleOportunidadUseCase
{
    public function __construct(
        private DetalleOportunidadRepositoryInterface $repository,
        private CalculoDetalleService $calculoService,
    ) {}

    public function execute(array $data): mixed
    {
        $producto = Producto::findOrFail($data['producto_id']);
        $ivaPorcentaje = $producto->iva;

        $data['medida'] = $data['medida'] ?? $producto->medida ?? 'Und';
        $data['iva'] = $producto->iva;

        $calculos = $this->calculoService->calculate(
            (float) $data['cantidad'],
            (float) $data['vr_unitario'],
            (float) $ivaPorcentaje
        );

        $data['iva'] = $calculos['iva'];
        // vr_total = (cantidad * vr_unitario) + iva
        $data['vr_total'] = $calculos['vr_total'] + $calculos['iva'];

        return $this->repository->create($data);
    }
}
