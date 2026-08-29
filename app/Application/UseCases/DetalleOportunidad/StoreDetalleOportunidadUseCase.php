<?php

namespace App\Application\UseCases\DetalleOportunidad;

use App\Application\Services\CalculoDetalleService;
use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Models\Producto;
use Modules\CRM\Models\DetalleOportunidad;

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

        // REQ-DOP-001: tipo_oferta defaults to 'servicio' if the caller
        // omits the field (the Form Request treats it as nullable so
        // validated() does not surface the key). Setting it explicitly
        // here keeps the Domain Entity in sync with what actually lands
        // in the DB (the column default would apply on insert, but the
        // post-insert model wouldn't see it without a refresh).
        $data['tipo_oferta'] = $data['tipo_oferta'] ?? 'servicio';
        if (! in_array($data['tipo_oferta'], DetalleOportunidad::TIPOS_OFERTA, true)) {
            // Defence-in-depth: the Form Request already rejects invalid
            // values, but if a programmatic caller bypasses the API edge
            // this protects the DB from out-of-allow-list writes.
            throw new \InvalidArgumentException(
                'tipo_oferta must be one of: '.implode(', ', DetalleOportunidad::TIPOS_OFERTA)
            );
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
