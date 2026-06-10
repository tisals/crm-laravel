<?php

namespace App\Application\UseCases\DetalleServicio;

use App\Application\Services\CalculoDetalleService;
use App\Domain\Repositories\DetalleServicioRepositoryInterface;
use App\Models\Producto;

class StoreDetalleServicioUseCase
{
    public function __construct(
        private DetalleServicioRepositoryInterface $repository,
        private CalculoDetalleService $calculoService,
    ) {}

    public function execute(array $data): mixed
    {
        $subTotal = round($data['precio'] * $data['cantidad'], 2);
        $data['sub_total'] = $subTotal;

        $ivaPorcentaje = 0;
        if (isset($data['producto_id'])) {
            $producto = Producto::find($data['producto_id']);
            if ($producto) {
                $ivaPorcentaje = (float) $producto->iva;
            }
        }

        $iva = round($subTotal * ($ivaPorcentaje / 100), 2);
        $data['iva'] = $iva;

        $descuento = $data['descuento'] ?? 0;
        $data['total'] = round($subTotal + $iva - $descuento, 2);

        return $this->repository->create($data);
    }
}
