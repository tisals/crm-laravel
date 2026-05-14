<?php

namespace Database\Factories;

use App\Models\Colaborador;
use Illuminate\Database\Eloquent\Factories\Factory;

class ColaboradorFactory extends Factory
{
    protected $model = Colaborador::class;

    public function definition(): array
    {
        return [
            'usuario_id' => null,
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName(),
            'tipo_id' => fake()->randomElement(['CC', 'CE', 'TI']),
            'identificacion' => fake()->unique()->numerify('##########'),
            'cargo' => fake()->optional(0.7)->jobTitle(),
            'area' => fake()->optional(0.6)->word(),
            'fecha_ingreso' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'contrato' => fake()->optional(0.5)->randomElement(['Indefinido', 'Temporal', 'Prestación de servicios']),
            'estado' => 'Activo',
        ];
    }
}
