<?php

namespace App\Application\Services;

class CalculoDetalleService
{
    public function calculate(float $cantidad, float $vrUnitario, float $ivaPorcentaje): array
    {
        $vrTotal = $cantidad * $vrUnitario;
        $iva = $vrTotal * ($ivaPorcentaje / 100);

        return [
            'vr_total' => round($vrTotal, 2),
            'iva' => round($iva, 2),
        ];
    }
}
