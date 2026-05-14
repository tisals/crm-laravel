<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Models\Ciudad;
use App\Models\Entidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetalleServicioControllerTest extends TestCase
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

    private function createReferences(): array
    {
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();
        $servicio = Servicio::factory()->create(['entidad_id' => $entidad->id]);
        $producto = Producto::factory()->create(['iva' => 19]);

        return ['servicio' => $servicio, 'producto' => $producto];
    }

    #[Test]
    public function it_lists_detalles_by_servicio(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/servicios/' . $refs['servicio']->id . '/detalles');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_detalle_with_auto_calc(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios/' . $refs['servicio']->id . '/detalles', [
                'producto_id' => $refs['producto']->id,
                'cantidad' => 2,
                'precio' => 100000,
                'observacion' => 'Detalle de prueba',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.servicio_id', $refs['servicio']->id)
            ->assertJsonPath('data.producto_id', $refs['producto']->id)
            ->assertJsonPath('data.cantidad', 2)
            ->assertJsonPath('data.precio', 100000);

        // Verify auto-calc: sub_total = 2 * 100000 = 200000
        // IVA = 200000 * 0.19 = 38000
        // total = 200000 + 38000 - 0 = 238000
        $response->assertJsonPath('data.sub_total', 200000)
            ->assertJsonPath('data.iva', 38000)
            ->assertJsonPath('data.total', 238000);
    }

    #[Test]
    public function it_shows_a_detalle(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios/' . $refs['servicio']->id . '/detalles', [
                'producto_id' => $refs['producto']->id,
                'cantidad' => 1,
                'precio' => 50000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/detalles-servicio/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id);
    }

    #[Test]
    public function it_updates_a_detalle(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios/' . $refs['servicio']->id . '/detalles', [
                'producto_id' => $refs['producto']->id,
                'cantidad' => 1,
                'precio' => 50000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/detalles-servicio/' . $id, [
                'cantidad' => 3,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cantidad', 3);
    }

    #[Test]
    public function it_deletes_a_detalle(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios/' . $refs['servicio']->id . '/detalles', [
                'producto_id' => $refs['producto']->id,
                'cantidad' => 1,
                'precio' => 50000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/detalles-servicio/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios/' . $refs['servicio']->id . '/detalles', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_nonexistent_detalle(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/detalles-servicio/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_handles_zero_iva_product(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();
        $productoSinIva = Producto::factory()->create(['iva' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios/' . $refs['servicio']->id . '/detalles', [
                'producto_id' => $productoSinIva->id,
                'cantidad' => 5,
                'precio' => 20000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sub_total', 100000)
            ->assertJsonPath('data.iva', 0)
            ->assertJsonPath('data.total', 100000);
    }

    #[Test]
    public function it_handles_descuento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios/' . $refs['servicio']->id . '/detalles', [
                'producto_id' => $refs['producto']->id,
                'cantidad' => 10,
                'precio' => 50000,
                'descuento' => 50000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        // sub_total = 10 * 50000 = 500000
        // iva = 500000 * 0.19 = 95000
        // total = 500000 + 95000 - 50000 = 545000
        $response->assertJsonPath('data.sub_total', 500000)
            ->assertJsonPath('data.iva', 95000)
            ->assertJsonPath('data.total', 545000);
    }
}
