<?php

namespace App\Application\UseCases\DetalleServicio;

use App\Domain\Repositories\DetalleServicioRepositoryInterface;

class UpdateDetalleServicioUseCase
{
    public function __construct(
        private DetalleServicioRepositoryInterface $repository,
        private \App\Application\Services\CalculoDetalleService $calculoService,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        $existing = $this->repository->findById($id);
        if (!$existing) {
            return null;
        }

        $precio = $data['precio'] ?? $existing->precio;
        $cantidad = $data['cantidad'] ?? $existing->cantidad;

        if (isset($data['precio']) || isset($data['cantidad'])) {
            $subTotal = round($precio * $cantidad, 2);
            $data['sub_total'] = $subTotal;

            $productoId = $data['producto_id'] ?? $existing->producto_id;
            $ivaPorcentaje = 0;
            if ($productoId) {
                $producto = \App\Models\Producto::find($productoId);
                if ($producto) {
                    $ivaPorcentaje = (float) $producto->iva;
                }
            }

            $iva = round($subTotal * ($ivaPorcentaje / 100), 2);
            $data['iva'] = $iva;

            $descuento = $data['descuento'] ?? $existing->descuento;
            $data['total'] = round($subTotal + $iva - $descuento, 2);
        }

        return $this->repository->update($id, $data);
    }
}
