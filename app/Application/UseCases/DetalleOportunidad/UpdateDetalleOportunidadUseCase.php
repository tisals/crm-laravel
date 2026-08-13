<?php

namespace App\Application\UseCases\DetalleOportunidad;

use App\Application\Services\CalculoDetalleService;
use App\Application\Services\RecomputeOportunidadTotalService;
use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Models\Producto;

class UpdateDetalleOportunidadUseCase
{
    /**
     * Fields that trigger totals recompute. Per spec scenario
     * "PUT only non-numeric fields preserves totals", updating text fields
     * (descripcion, notas, concepto, medida) MUST NOT alter iva/vr_total.
     */
    private const RECOMPUTE_TRIGGER_FIELDS = [
        'cantidad',
        'vr_unitario',
        'producto_id',
        'descuento',
    ];

    public function __construct(
        private DetalleOportunidadRepositoryInterface $repository,
        private CalculoDetalleService $calculoService,
        private RecomputeOportunidadTotalService $recomputeService,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        $shouldRecompute = false;
        foreach (self::RECOMPUTE_TRIGGER_FIELDS as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $shouldRecompute = true;
                break;
            }
        }

        if ($shouldRecompute) {
            $existing = $this->repository->findById($id);
            if ($existing) {
                $cantidad = $data['cantidad'] ?? $existing->cantidad;
                $vrUnitario = $data['vr_unitario'] ?? $existing->vr_unitario;

                $productoId = $data['producto_id'] ?? $existing->producto_id;
                $producto = Producto::findOrFail($productoId);

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

        $updated = $this->repository->update($id, $data);

        if ($updated && $shouldRecompute) {
            // Recompute parent Oportunidad aggregate total (no-op if column absent).
            $this->recomputeService->recompute($updated->oportunidad_id);
        }

        return $updated;
    }
}
