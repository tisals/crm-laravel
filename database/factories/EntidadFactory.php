<?php

namespace Database\Factories;

use App\Models\Entidad;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntidadFactory extends Factory
{
    protected $model = Entidad::class;

    public function definition(): array
    {
        return [
            'tipo_persona' => fake()->randomElement(['Natural', 'Juridica']),
            'tipo_id' => fake()->randomElement(['NIT', 'CC', 'CE']),
            'identificacion' => fake()->unique()->numerify('##########'),
            'nombre' => fake()->company(),
            'nombre_comercial' => fake()->optional(0.6)->company(),
            'direccion' => fake()->address(),
            'ciudad_cod' => '05001',
            'estado' => 'Activo',
        ];
    }
}
