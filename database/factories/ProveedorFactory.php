<?php

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    public function definition(): array
    {
        return [
            'tipo_id' => fake()->randomElement(['NIT', 'CC', 'CE']),
            'identificacion' => fake()->unique()->numerify('##########'),
            'nombres' => fake()->optional(0.7)->firstName(),
            'apellidos' => fake()->optional(0.7)->lastName(),
            'profesion' => fake()->optional(0.6)->word(),
            'especialidad' => fake()->optional(0.5)->word(),
            'iva' => fake()->optional(0.8)->randomFloat(2, 0, 100),
            'retenciones' => fake()->optional(0.6)->randomFloat(2, 0, 100),
            'ciudad_cod' => '05001',
            'fecha_registro' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'estado' => 'Activo',
        ];
    }
}
