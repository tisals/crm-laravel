<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsuarioControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): string
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return $usuario->createToken('test-token')->plainTextToken;
    }

    #[Test]
    public function it_lists_usuarios(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/usuarios');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_usuario(): void
    {
        $token = $this->authenticate();
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/usuarios', [
                'nombre' => 'New User',
                'email' => 'newuser@test.com',
                'password' => 'password123',
                'rol_id' => $rol->id,
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'New User');
    }

    #[Test]
    public function it_shows_a_usuario(): void
    {
        $token = $this->authenticate();
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);
        $usuario = Usuario::create([
            'nombre' => 'View User',
            'email' => 'view@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/usuarios/' . $usuario->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'View User');
    }

    #[Test]
    public function it_updates_a_usuario(): void
    {
        $token = $this->authenticate();
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);
        $usuario = Usuario::create([
            'nombre' => 'Old Name',
            'email' => 'old@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/usuarios/' . $usuario->id, [
                'nombre' => 'Updated Name',
                'email' => 'old@test.com',
                'rol_id' => $rol->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Updated Name');
    }

    #[Test]
    public function it_deletes_a_usuario(): void
    {
        $token = $this->authenticate();
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);
        $usuario = Usuario::create([
            'nombre' => 'Delete User',
            'email' => 'delete@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/usuarios/' . $usuario->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_toggles_usuario_status(): void
    {
        $token = $this->authenticate();
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);
        $usuario = Usuario::create([
            'nombre' => 'Toggle User',
            'email' => 'toggle@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/usuarios/' . $usuario->id . '/status');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'Inactivo');
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/usuarios', []);

        $response->assertStatus(422);
    }
}
