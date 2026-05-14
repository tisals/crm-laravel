<?php

namespace Tests;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a Usuario with a Sanctum token for authenticated testing.
     */
    protected function actingAsUsuario(array $overrides = []): array
    {
        $rol = \App\Models\Rol::create([
            'nombre' => $overrides['rol_nombre'] ?? 'Admin',
            'estado' => 'Activo',
        ]);

        unset($overrides['rol_nombre']);

        $usuario = Usuario::create(array_merge([
            'nombre' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ], $overrides));

        $token = $usuario->createToken('test-token')->plainTextToken;

        return ['usuario' => $usuario, 'token' => $token, 'rol' => $rol];
    }
}
