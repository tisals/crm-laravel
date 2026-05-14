<?php

namespace Database\Factories;

use App\Models\Cuenta;
use Illuminate\Database\Eloquent\Factories\Factory;

class CuentaFactory extends Factory
{
    protected $model = Cuenta::class;

    public function definition(): array
    {
        return [
            'proveedor_id' => \App\Models\Proveedor::factory(),
            'banco' => fake()->randomElement(['Bancolombia', 'Davivienda', 'BBVA', 'Banco de Bogotá', 'Nequi']),
            'numero_cuenta' => fake()->bankAccountNumber(),
            'tipo' => fake()->randomElement(['Ahorros', 'Corriente']),
            'estado' => 'Activo',
        ];
    }
}
