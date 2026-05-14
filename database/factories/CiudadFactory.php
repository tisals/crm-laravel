<?php

namespace Database\Factories;

use App\Models\Ciudad;
use Illuminate\Database\Eloquent\Factories\Factory;

class CiudadFactory extends Factory
{
    protected $model = Ciudad::class;

    public function definition(): array
    {
        return [
            'cod_municipio' => str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'nombre' => fake()->city(),
            'departamento' => fake()->state(),
        ];
    }
}
