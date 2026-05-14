<?php

namespace Database\Factories;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductoFactory extends Factory
{
    protected $model = Producto::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->words(3, true),
            'linea_negocio' => fake()->randomElement(['Tecnología', 'Servicios', 'Consultoría', null]),
            'iva' => fake()->randomElement([0, 5, 16, 19]),
            'estado' => 'Activo',
        ];
    }
}
