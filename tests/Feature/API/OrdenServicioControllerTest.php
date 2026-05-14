<?php

namespace Tests\Feature\API;

use App\Models\Colaborador;
use App\Models\Contacto;
use App\Models\DetalleServicio;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Models\Ciudad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrdenServicioControllerTest extends TestCase
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
        $servicio = Servicio::factory()->create(['entidad_id' => $entidad->id]);
        $producto = Producto::factory()->create();
        $detalleServicio = DetalleServicio::factory()->create([
            'servicio_id' => $servicio->id,
            'producto_id' => $producto->id,
        ]);
        $colaborador = Colaborador::factory()->create(['usuario_id' => $usuario->id]);
        $proveedor = Proveedor::factory()->create();
        $contacto = Contacto::factory()->create();

        return [
            'detalleServicio' => $detalleServicio,
            'colaborador' => $colaborador,
            'proveedor' => $proveedor,
            'contacto' => $contacto,
        ];
    }

    #[Test]
    public function it_lists_ordenes_servicio(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/ordenes-servicio');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_an_orden_with_colaborador(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/ordenes-servicio', [
                'detalle_srv_id' => $refs['detalleServicio']->id,
                'colaborador_id' => $refs['colaborador']->id,
                'contacto_id' => $refs['contacto']->id,
                'descripcion' => 'Instalación de equipos',
                'objetivo' => 'Completar instalación en sitio',
                'ubicacion' => 'Oficina principal',
                'fecha_desde' => '2026-05-15 08:00:00',
                'fecha_hasta' => '2026-05-15 17:00:00',
                'valor' => 500000,
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.detalle_srv_id', $refs['detalleServicio']->id)
            ->assertJsonPath('data.colaborador_id', $refs['colaborador']->id)
            ->assertJsonPath('data.estado', 'Pendiente');
    }

    #[Test]
    public function it_creates_an_orden_with_proveedor(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/ordenes-servicio', [
                'detalle_srv_id' => $refs['detalleServicio']->id,
                'proveedor_id' => $refs['proveedor']->id,
                'contacto_id' => $refs['contacto']->id,
                'descripcion' => 'Servicio externo',
                'valor' => 1200000,
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.proveedor_id', $refs['proveedor']->id);
    }

    #[Test]
    public function it_rejects_orden_without_colaborador_or_proveedor(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/ordenes-servicio', [
                'detalle_srv_id' => $refs['detalleServicio']->id,
                'contacto_id' => $refs['contacto']->id,
                'descripcion' => 'Sin responsable',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_shows_an_orden(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/ordenes-servicio', [
                'colaborador_id' => $refs['colaborador']->id,
                'descripcion' => 'Testing show',
                'estado' => 'Pendiente',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/ordenes-servicio/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id);
    }

    #[Test]
    public function it_updates_an_orden(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/ordenes-servicio', [
                'colaborador_id' => $refs['colaborador']->id,
                'descripcion' => 'Estado inicial',
                'estado' => 'Pendiente',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/ordenes-servicio/' . $id, [
                'estado' => 'EnProgreso',
                'descripcion' => 'En ejecución',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'EnProgreso');
    }

    #[Test]
    public function it_deletes_an_orden(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/ordenes-servicio', [
                'colaborador_id' => $refs['colaborador']->id,
                'descripcion' => 'A eliminar',
                'estado' => 'Pendiente',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/ordenes-servicio/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_returns_404_for_missing_orden(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/ordenes-servicio/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
