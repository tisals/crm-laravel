<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Permiso;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProveedorControllerTest extends TestCase
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
    public function it_lists_proveedores(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/proveedores');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_proveedor(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/proveedores', [
                'tipo_id' => 'NIT',
                'identificacion' => '800123456',
                'nombres' => 'Carlos',
                'apellidos' => 'Mendoza',
                'profesion' => 'Consultor',
                'especialidad' => 'Seguridad',
                'iva' => 19.00,
                'retenciones' => 10.00,
                'ciudad_cod' => '05001',
                'fecha_registro' => '2025-06-01',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombres', 'Carlos')
            ->assertJsonPath('data.identificacion', '800123456');
    }

    #[Test]
    public function it_shows_a_proveedor(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $proveedor = Proveedor::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/proveedores/' . $proveedor->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $proveedor->id);
    }

    #[Test]
    public function it_updates_a_proveedor(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $proveedor = Proveedor::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/proveedores/' . $proveedor->id, [
                'nombres' => 'Updated Name',
                'identificacion' => $proveedor->identificacion,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombres', 'Updated Name');
    }

    #[Test]
    public function it_deletes_a_proveedor(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $proveedor = Proveedor::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/proveedores/' . $proveedor->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_searches_proveedores(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        Proveedor::factory()->create(['nombres' => 'Proveedor Uno', 'identificacion' => '1111111111']);
        Proveedor::factory()->create(['nombres' => 'Proveedor Dos', 'identificacion' => '2222222222']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/proveedores?search=Uno');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('Proveedor Uno', $data[0]['nombres']);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/proveedores', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_proveedor(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/proveedores/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
