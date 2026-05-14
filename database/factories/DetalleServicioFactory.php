<?php

namespace Database\Factories;

use App\Models\DetalleServicio;
use Illuminate\Database\Eloquent\Factories\Factory;

class DetalleServicioFactory extends Factory
{
    protected $model = DetalleServicio::class;

    public function definition(): array
    {
        $cantidad = fake()->randomFloat(2, 1, 100);
        $precio = fake()->randomFloat(2, 5000, 500000);
        $subTotal = round($cantidad * $precio, 2);
        $iva = round($subTotal * 0.19, 2);

        return [
            'servicio_id' => \App\Models\Servicio::factory(),
            'producto_id' => \App\Models\Producto::factory(),
            'observacion' => fake()->optional(0.5)->sentence(),
            'cantidad' => $cantidad,
            'precio' => $precio,
            'descuento' => 0,
            'sub_total' => $subTotal,
            'iva' => $iva,
            'total' => round($subTotal + $iva, 2),
        ];
    }
}
