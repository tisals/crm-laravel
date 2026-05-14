<?php

namespace Database\Factories;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;

class PermisoFactory extends Factory
{
    protected $model = Permiso::class;

    public function definition(): array
    {
        return [
            'rol_id' => Rol::factory(),
            'vista' => fake()->word() . '.' . fake()->randomElement(['index', 'store', 'show', 'update', 'destroy']),
        ];
    }
}
