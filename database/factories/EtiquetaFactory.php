<?php

namespace Database\Factories;

use App\Models\Etiqueta;
use Illuminate\Database\Eloquent\Factories\Factory;

class EtiquetaFactory extends Factory
{
    protected $model = Etiqueta::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->word(),
            'estado' => 'Activo',
        ];
    }
}
