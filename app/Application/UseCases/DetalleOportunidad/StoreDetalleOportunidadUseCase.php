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

        // Use request iva if provided, otherwise fall back to product's iva
        $ivaPorcentaje = isset($data['iva']) && $data['iva'] !== null
            ? (float) $data['iva']
            : (float) $producto->iva;

        $data['medida'] = $data['medida'] ?? $producto->medida ?? 'Und';

        // Fill concepto/descripcion from product if not provided
        if (empty($data['concepto'])) {
            $data['concepto'] = $producto->descripcion ?? $producto->nombre;
        }
        if (empty($data['descripcion'])) {
            $data['descripcion'] = $producto->descripcion ?? $producto->nombre;
        }

        $calculos = $this->calculoService->calculate(
            (float) $data['cantidad'],
            (float) $data['vr_unitario'],
            $ivaPorcentaje
        );

        $data['iva'] = $calculos['iva'];
        // vr_total = (cantidad * vr_unitario) + iva
        $data['vr_total'] = $calculos['vr_total'] + $calculos['iva'];

        return $this->repository->create($data);
    }
}
