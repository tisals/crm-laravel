<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Oportunidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetalleOportunidadControllerTest extends TestCase
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

    private function createOportunidad(): Oportunidad
    {
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);

        return Oportunidad::create([
            'codigo' => 'COT-000001',
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'fecha' => '2026-05-10',
            'estado' => 'Borrador',
        ]);
    }

    #[Test]
    public function it_lists_detalles_by_oportunidad(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/oportunidades/{$oportunidad->id}/detalles");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_detalle(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Consultoría ERP',
                'medida' => 'Srv',
                'cantidad' => 2,
                'vr_unitario' => 100000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.producto_id', $producto->id)
            ->assertJsonPath('data.cantidad', 2)
            ->assertJsonPath('data.vr_unitario', 100000);

        // Auto-calc: vr_total = (2 * 100000) + (200000 * 0.19) = 200000 + 38000 = 238000
        $response->assertJsonPath('data.vr_total', 238000);
        $response->assertJsonPath('data.iva', 38000);
    }

    #[Test]
    public function it_shows_a_detalle(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Consultoría ERP',
                'medida' => 'Srv',
                'cantidad' => 1,
                'vr_unitario' => 50000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/detalles-oportunidad/{$id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id);
    }

    #[Test]
    public function it_updates_a_detalle(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Consultoría ERP',
                'medida' => 'Srv',
                'cantidad' => 1,
                'vr_unitario' => 50000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson("/api/v1/detalles-oportunidad/{$id}", [
                'cantidad' => 3,
                'vr_unitario' => 50000,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cantidad', 3);
    }

    #[Test]
    public function it_deletes_a_detalle(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 19]);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Test',
                'medida' => 'Und',
                'cantidad' => 1,
                'vr_unitario' => 10000,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson("/api/v1/detalles-oportunidad/{$id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_nonexistent_detalle(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/detalles-oportunidad/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_handles_zero_iva_product(): void
    {
        $token = $this->authenticate();
        $oportunidad = $this->createOportunidad();
        $producto = Producto::factory()->create(['iva' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/oportunidades/{$oportunidad->id}/detalles", [
                'producto_id' => $producto->id,
                'concepto' => 'Producto exento',
                'medida' => 'Und',
                'cantidad' => 5,
                'vr_unitario' => 10000,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.iva', 0)
            // vr_total = (5 * 10000) + 0 = 50000
            ->assertJsonPath('data.vr_total', 50000);
    }
}
