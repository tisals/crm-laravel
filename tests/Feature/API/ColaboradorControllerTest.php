<?php

namespace Tests\Feature\API;

use App\Models\Colaborador;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ColaboradorControllerTest extends TestCase
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
    public function it_lists_colaboradores(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/colaboradores');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_colaborador(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/colaboradores', [
                'nombres' => 'Pedro',
                'apellidos' => 'Ramírez',
                'tipo_id' => 'CC',
                'identificacion' => '1234567890',
                'cargo' => 'Desarrollador',
                'area' => 'Tecnología',
                'fecha_ingreso' => '2025-01-15',
                'contrato' => 'Indefinido',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombres', 'Pedro')
            ->assertJsonPath('data.identificacion', '1234567890');
    }

    #[Test]
    public function it_shows_a_colaborador(): void
    {
        $token = $this->authenticate();
        $colaborador = Colaborador::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/colaboradores/' . $colaborador->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $colaborador->id);
    }

    #[Test]
    public function it_updates_a_colaborador(): void
    {
        $token = $this->authenticate();
        $colaborador = Colaborador::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/colaboradores/' . $colaborador->id, [
                'nombres' => 'Updated Name',
                'apellidos' => $colaborador->apellidos,
                'identificacion' => $colaborador->identificacion,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombres', 'Updated Name');
    }

    #[Test]
    public function it_deletes_a_colaborador(): void
    {
        $token = $this->authenticate();
        $colaborador = Colaborador::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/colaboradores/' . $colaborador->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_searches_colaboradores(): void
    {
        $token = $this->authenticate();
        Colaborador::factory()->create(['nombres' => 'Ana', 'apellidos' => 'Martínez', 'identificacion' => '1111111111']);
        Colaborador::factory()->create(['nombres' => 'Luis', 'apellidos' => 'García', 'identificacion' => '2222222222']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/colaboradores?search=Ana');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('Ana', $data[0]['nombres']);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/colaboradores', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_colaborador(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/colaboradores/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
