<?php

namespace Database\Factories;

use App\Models\Movimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

class MovimientoFactory extends Factory
{
    protected $model = Movimiento::class;

    public function definition(): array
    {
        $isDebito = fake()->boolean();

        return [
            'fecha' => fake()->date(),
            'valor_debito' => $isDebito ? fake()->randomFloat(2, 10000, 10000000) : 0,
            'valor_credito' => !$isDebito ? fake()->randomFloat(2, 10000, 10000000) : 0,
            'proveedor_id' => fake()->optional(0.4)->randomElement(\App\Models\Proveedor::pluck('id')->toArray() ?: [null]),
            'colaborador_id' => null,
            'servicio_id' => null,
            'observaciones' => fake()->optional(0.6)->sentence(),
        ];
    }
}
