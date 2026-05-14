<?php

namespace Database\Factories;

use App\Models\OrdenServicio;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrdenServicioFactory extends Factory
{
    protected $model = OrdenServicio::class;

    public function definition(): array
    {
        return [
            'detalle_srv_id' => null,
            'colaborador_id' => \App\Models\Colaborador::factory(),
            'proveedor_id' => null,
            'contacto_id' => null,
            'descripcion' => fake()->optional(0.7)->sentence(),
            'objetivo' => fake()->optional(0.6)->sentence(),
            'ubicacion' => fake()->optional(0.5)->address(),
            'fecha_desde' => fake()->dateTimeBetween('-1 month', '+1 month'),
            'fecha_hasta' => fake()->optional(0.7)->dateTimeBetween('+1 day', '+2 months'),
            'valor' => fake()->randomFloat(2, 50000, 5000000),
            'estado' => fake()->randomElement(['Pendiente', 'EnProgreso', 'Completado', 'Cancelado']),
        ];
    }
}
