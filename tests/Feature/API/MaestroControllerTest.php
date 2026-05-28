<?php

namespace Tests\Feature\API;

use App\Models\Maestro;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MaestroControllerTest extends TestCase
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
    public function it_lists_maestros(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/maestros');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_maestro(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/maestros', [
                'nombre' => 'Consultoría TI',
                'campo' => 'linea_negocio',
                'habilitado' => 'Y',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Consultoría TI');
    }

    #[Test]
    public function it_shows_a_maestro(): void
    {
        $token = $this->authenticate();
        $maestro = Maestro::create([
            'nombre' => 'Consultoría TI',
            'campo' => 'linea_negocio',
            'habilitado' => 'Y',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/maestros/' . $maestro->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $maestro->id);
    }

    #[Test]
    public function it_updates_a_maestro(): void
    {
        $token = $this->authenticate();
        $maestro = Maestro::create([
            'nombre' => 'Consultoría TI',
            'campo' => 'linea_negocio',
            'habilitado' => 'Y',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/maestros/' . $maestro->id, [
                'nombre' => 'Consultoría ERP',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Consultoría ERP');
    }

    #[Test]
    public function it_deletes_a_maestro(): void
    {
        $token = $this->authenticate();
        $maestro = Maestro::create([
            'nombre' => 'Consultoría TI',
            'campo' => 'linea_negocio',
            'habilitado' => 'Y',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/maestros/' . $maestro->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('maestros', ['id' => $maestro->id]);
    }

    #[Test]
    public function it_returns_422_on_validation_failure(): void
    {
        $token = $this->authenticate();

        // Missing nombre
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/maestros', [
                'campo' => 'linea_negocio',
                'habilitado' => 'Y',
            ]);

        $response->assertStatus(422);

        // Invalid habilitado value
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/maestros', [
                'nombre' => 'Test',
                'campo' => 'linea_negocio',
                'habilitado' => 'X',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_when_maestro_not_found(): void
    {
        $token = $this->authenticate();

        // Show
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/maestros/99999');
        $response->assertStatus(404);

        // Update
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/maestros/99999', ['nombre' => 'Test']);
        $response->assertStatus(404);

        // Delete
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/maestros/99999');
        $response->assertStatus(404);
    }
}
