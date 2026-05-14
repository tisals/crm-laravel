<?php

namespace Tests\Feature\API;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_logs_in_with_valid_credentials(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Usuario::create([
            'nombre' => 'Test User',
            'email' => 'test@example.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'usuario' => ['id', 'nombre', 'email', 'rol_id', 'estado'],
                ],
            ])
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_rejects_invalid_credentials(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Usuario::create([
            'nombre' => 'Test User',
            'email' => 'test@example.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_rejects_inactive_user(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Usuario::create([
            'nombre' => 'Inactive User',
            'email' => 'inactive@example.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Inactivo',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Usuario inactivo.');
    }

    #[Test]
    public function it_logs_out_with_valid_token(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        $usuario = Usuario::create([
            'nombre' => 'Test User',
            'email' => 'test@example.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);
        $token = $usuario->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_rejects_logout_without_token(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }
}
