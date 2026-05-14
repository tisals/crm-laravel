<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LugarEntidadControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): array
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

        $token = $usuario->createToken('test-token')->plainTextToken;

        return ['token' => $token, 'usuario' => $usuario];
    }

    #[Test]
    public function it_lists_lugares_by_entidad(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/entidad/' . $entidad->id . '/lugares');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_lugar_for_entidad(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/entidad/' . $entidad->id . '/lugares', [
                'area_oficina' => 'Sede Principal',
                'direccion' => 'Av. Principal #123',
                'ciudad_cod' => '05001',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.area_oficina', 'Sede Principal');
    }

    #[Test]
    public function it_shows_a_lugar(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/entidad/' . $entidad->id . '/lugares', [
                'area_oficina' => 'Oficina Norte',
                'direccion' => 'Calle Norte #456',
                'ciudad_cod' => '05001',
            ]);

        $lugarId = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/entidad/' . $entidad->id . '/lugares/' . $lugarId);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.area_oficina', 'Oficina Norte');
    }

    #[Test]
    public function it_updates_a_lugar(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/entidad/' . $entidad->id . '/lugares', [
                'area_oficina' => 'Original',
                'direccion' => 'Dir Original',
                'ciudad_cod' => '05001',
            ]);

        $lugarId = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->putJson('/api/v1/entidad/' . $entidad->id . '/lugares/' . $lugarId, [
                'area_oficina' => 'Updated Office',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.area_oficina', 'Updated Office');
    }

    #[Test]
    public function it_deletes_a_lugar(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/entidad/' . $entidad->id . '/lugares', [
                'area_oficina' => 'Temp Office',
                'direccion' => 'Temp Dir',
                'ciudad_cod' => '05001',
            ]);

        $lugarId = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->deleteJson('/api/v1/entidad/' . $entidad->id . '/lugares/' . $lugarId);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->postJson('/api/v1/entidad/' . $entidad->id . '/lugares', []);

        $response->assertStatus(422);
    }
}
