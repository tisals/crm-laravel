<?php

namespace Tests\Feature\API;

use App\Models\Colaborador;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Models\Ciudad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MovimientoControllerTest extends TestCase
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
        $usuario = Usuario::factory()->create(['rol_id' => Rol::factory()->create(['estado' => 'Activo'])->id]);
        $entidad = Entidad::factory()->create();
        $proveedor = Proveedor::factory()->create();
        $colaborador = Colaborador::factory()->create(['usuario_id' => $usuario->id]);
        $servicio = Servicio::factory()->create(['entidad_id' => $entidad->id]);

        return [
            'proveedor' => $proveedor,
            'colaborador' => $colaborador,
            'servicio' => $servicio,
        ];
    }

    #[Test]
    public function it_lists_movimientos(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/movimientos');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_movimiento_debito(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/movimientos', [
                'fecha' => '2026-05-10',
                'valor_debito' => 500000,
                'valor_credito' => 0,
                'proveedor_id' => $refs['proveedor']->id,
                'observaciones' => 'Pago a proveedor',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.fecha', '2026-05-10')
            ->assertJsonPath('data.valor_debito', 500000)
            ->assertJsonPath('data.valor_credito', 0);
    }

    #[Test]
    public function it_creates_a_movimiento_credito(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/movimientos', [
                'fecha' => '2026-05-10',
                'valor_debito' => 0,
                'valor_credito' => 1000000,
                'servicio_id' => $refs['servicio']->id,
                'observaciones' => 'Ingreso por servicio',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valor_credito', 1000000);
    }

    #[Test]
    public function it_rejects_zero_debit_and_credit(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/movimientos', [
                'fecha' => '2026-05-10',
                'valor_debito' => 0,
                'valor_credito' => 0,
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_rejects_both_debit_and_credit(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/movimientos', [
                'fecha' => '2026-05-10',
                'valor_debito' => 500000,
                'valor_credito' => 300000,
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_shows_a_movimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/movimientos', [
                'fecha' => '2026-05-10',
                'valor_debito' => 250000,
                'valor_credito' => 0,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/movimientos/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id);
    }

    #[Test]
    public function it_updates_a_movimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/movimientos', [
                'fecha' => '2026-05-10',
                'valor_debito' => 100000,
                'valor_credito' => 0,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/movimientos/' . $id, [
                'valor_debito' => 150000,
                'observaciones' => 'Actualizado',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.valor_debito', 150000);
    }

    #[Test]
    public function it_deletes_a_movimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/movimientos', [
                'fecha' => '2026-05-10',
                'valor_debito' => 50000,
                'valor_credito' => 0,
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/movimientos/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_returns_404_for_missing_movimiento(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/movimientos/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
