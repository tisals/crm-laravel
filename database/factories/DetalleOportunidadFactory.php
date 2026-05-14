<?php

namespace Database\Factories;

use App\Models\DetalleOportunidad;
use Illuminate\Database\Eloquent\Factories\Factory;

class DetalleOportunidadFactory extends Factory
{
    protected $model = DetalleOportunidad::class;

    public function definition(): array
    {
        $cantidad = fake()->randomFloat(2, 1, 10);
        $vrUnitario = fake()->randomFloat(2, 10000, 500000);
        $vrTotal = $cantidad * $vrUnitario;
        $iva = $vrTotal * 0.19;

        return [
            'oportunidad_id' => \App\Models\Oportunidad::factory(),
            'producto_id' => \App\Models\Producto::factory(),
            'concepto' => fake()->optional(0.7)->sentence(3),
            'medida' => fake()->randomElement(['Und', 'Hrs', 'Srv']),
            'cantidad' => $cantidad,
            'vr_unitario' => $vrUnitario,
            'iva' => round($iva, 2),
            'vr_total' => round($vrTotal + $iva, 2),
        ];
    }
}
