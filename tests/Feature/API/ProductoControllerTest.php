<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductoControllerTest extends TestCase
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
    public function it_lists_productos(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/productos');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_producto(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/productos', [
                'nombre' => 'Servicio de Consultoría',
                'linea_negocio' => 'Consultoría',
                'iva' => 19.00,
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Servicio de Consultoría');
    }

    #[Test]
    public function it_shows_a_producto(): void
    {
        $token = $this->authenticate();
        $producto = Producto::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/productos/' . $producto->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $producto->id);
    }

    #[Test]
    public function it_updates_a_producto(): void
    {
        $token = $this->authenticate();
        $producto = Producto::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/productos/' . $producto->id, [
                'nombre' => 'Updated Product',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Updated Product');
    }

    #[Test]
    public function it_deletes_a_producto(): void
    {
        $token = $this->authenticate();
        $producto = Producto::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/productos/' . $producto->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_searches_productos_by_name(): void
    {
        $token = $this->authenticate();
        Producto::create(['nombre' => 'Servicio Web', 'linea_negocio' => 'Tecnología', 'iva' => 19, 'estado' => 'Activo']);
        Producto::create(['nombre' => 'Consultoría ERP', 'linea_negocio' => 'Consultoría', 'iva' => 19, 'estado' => 'Activo']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/productos?search=Web');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('Servicio Web', $data[0]['nombre']);
    }

    #[Test]
    public function it_validates_iva_range(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/productos', [
                'nombre' => 'Test',
                'iva' => 150,
            ]);

        $response->assertStatus(422);
    }
}
