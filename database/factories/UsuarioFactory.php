<?php

namespace Database\Factories;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'nombre' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => bcrypt('password123'),
            'rol_id' => Rol::factory(),
            'estado' => 'Activo',
        ];
    }
}
