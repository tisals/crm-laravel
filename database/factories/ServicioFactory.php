<?php

namespace Database\Factories;

use App\Models\Servicio;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServicioFactory extends Factory
{
    protected $model = Servicio::class;

    public function definition(): array
    {
        return [
            'entidad_id' => \App\Models\Entidad::factory(),
            'nombre' => fake()->sentence(3),
            'vr_servicio' => fake()->randomFloat(2, 100000, 50000000),
            'fecha_inicio' => fake()->date(),
            'fecha_fin' => fake()->optional(0.7)->date(),
            'prestador_id' => null,
            'estado' => fake()->randomElement(['Nuevo', 'EnEjecucion', 'Finalizado', 'Cancelado']),
        ];
    }
}
