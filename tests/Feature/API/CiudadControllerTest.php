<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CiudadControllerTest extends TestCase
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
    public function it_lists_ciudades(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        Ciudad::create(['cod_municipio' => '11001', 'nombre' => 'Bogotá', 'departamento' => 'Cundinamarca']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/ciudades');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_filters_ciudades_by_departamento(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        Ciudad::create(['cod_municipio' => '05360', 'nombre' => 'Itagüí', 'departamento' => 'Antioquia']);
        Ciudad::create(['cod_municipio' => '11001', 'nombre' => 'Bogotá', 'departamento' => 'Cundinamarca']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/ciudades?departamento=Antioquia');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(2, $data);
    }

    #[Test]
    public function it_shows_a_ciudad_by_cod_municipio(): void
    {
        $auth = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/ciudades/05001');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Medellín');
    }

    #[Test]
    public function it_returns_404_for_nonexistent_ciudad(): void
    {
        $auth = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $auth['token'])
            ->getJson('/api/v1/ciudades/99999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
