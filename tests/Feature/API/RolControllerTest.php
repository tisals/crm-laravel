<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RolControllerTest extends TestCase
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
    public function it_lists_roles(): void
    {
        $token = $this->authenticate();
        Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);
        Rol::create(['nombre' => 'Finanzas', 'estado' => 'Activo']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/roles');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page', 'last_page', 'total']]);
    }

    #[Test]
    public function it_creates_a_role(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/roles', [
                'nombre' => 'Marketing',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Marketing');
    }

    #[Test]
    public function it_shows_a_role(): void
    {
        $token = $this->authenticate();
        $rol = Rol::create(['nombre' => 'Operaciones', 'estado' => 'Activo']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/roles/' . $rol->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Operaciones');
    }

    #[Test]
    public function it_updates_a_role(): void
    {
        $token = $this->authenticate();
        $rol = Rol::create(['nombre' => 'Old Name', 'estado' => 'Activo']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/roles/' . $rol->id, [
                'nombre' => 'New Name',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'New Name');
    }

    #[Test]
    public function it_deletes_a_role(): void
    {
        $token = $this->authenticate();
        $rol = Rol::create(['nombre' => 'Temp', 'estado' => 'Activo']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/roles/' . $rol->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_returns_404_for_missing_role(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/roles/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/roles', []);

        $response->assertStatus(422);
    }
}
