<?php

namespace Database\Factories;

use App\Models\LugarEntidad;
use Illuminate\Database\Eloquent\Factories\Factory;

class LugarEntidadFactory extends Factory
{
    protected $model = LugarEntidad::class;

    public function definition(): array
    {
        return [
            'entidad_id' => null,
            'area_oficina' => fake()->optional(0.7)->word(),
            'direccion' => fake()->address(),
            'ciudad_cod' => '05001',
            'estado' => 'Activo',
        ];
    }
}
