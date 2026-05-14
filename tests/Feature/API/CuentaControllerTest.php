<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Ciudad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CuentaControllerTest extends TestCase
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
        $proveedor = Proveedor::factory()->create();

        return ['proveedor' => $proveedor];
    }

    #[Test]
    public function it_lists_cuentas(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/cuentas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_cuenta(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/cuentas', [
                'proveedor_id' => $refs['proveedor']->id,
                'banco' => 'Bancolombia',
                'numero_cuenta' => '1234567890',
                'tipo' => 'Ahorros',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.proveedor_id', $refs['proveedor']->id)
            ->assertJsonPath('data.banco', 'Bancolombia')
            ->assertJsonPath('data.numero_cuenta', '1234567890')
            ->assertJsonPath('data.tipo', 'Ahorros');
    }

    #[Test]
    public function it_shows_a_cuenta(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/cuentas', [
                'proveedor_id' => $refs['proveedor']->id,
                'banco' => 'Davivienda',
                'numero_cuenta' => '987654321',
                'tipo' => 'Corriente',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/cuentas/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.banco', 'Davivienda');
    }

    #[Test]
    public function it_updates_a_cuenta(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/cuentas', [
                'proveedor_id' => $refs['proveedor']->id,
                'banco' => 'BBVA',
                'numero_cuenta' => '555555',
                'tipo' => 'Ahorros',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/cuentas/' . $id, [
                'banco' => 'Banco de Bogotá',
                'estado' => 'Inactivo',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.banco', 'Banco de Bogotá')
            ->assertJsonPath('data.estado', 'Inactivo');
    }

    #[Test]
    public function it_deletes_a_cuenta(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/cuentas', [
                'proveedor_id' => $refs['proveedor']->id,
                'banco' => 'Nequi',
                'numero_cuenta' => '111111',
                'tipo' => 'Ahorros',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/cuentas/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('cuentas', ['id' => $id]); // Permanent delete — no softDeletes
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/cuentas', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_cuenta(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/cuentas/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_lists_cuentas_by_proveedor(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/proveedores/' . $refs['proveedor']->id . '/cuentas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
