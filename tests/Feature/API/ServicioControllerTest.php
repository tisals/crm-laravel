<?php

namespace Tests\Feature\API;

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

class ServicioControllerTest extends TestCase
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
        $proveedor = Proveedor::factory()->create();

        return ['entidad' => $entidad, 'proveedor' => $proveedor];
    }

    #[Test]
    public function it_lists_servicios(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/servicios');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_servicio(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios', [
                'entidad_id' => $refs['entidad']->id,
                'nombre' => 'Desarrollo de Software CRM',
                'vr_servicio' => 15000000,
                'fecha_inicio' => '2026-05-01',
                'fecha_fin' => '2026-08-31',
                'prestador_id' => $refs['proveedor']->id,
                'estado' => 'Nuevo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Desarrollo de Software CRM')
            ->assertJsonPath('data.entidad_id', $refs['entidad']->id)
            ->assertJsonPath('data.estado', 'Nuevo');
    }

    #[Test]
    public function it_shows_a_servicio(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios', [
                'entidad_id' => $refs['entidad']->id,
                'nombre' => 'Mantenimiento Servidores',
                'vr_servicio' => 5000000,
                'estado' => 'Nuevo',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/servicios/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.nombre', 'Mantenimiento Servidores');
    }

    #[Test]
    public function it_updates_a_servicio(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios', [
                'entidad_id' => $refs['entidad']->id,
                'nombre' => 'Servicio Inicial',
                'vr_servicio' => 10000000,
                'estado' => 'Nuevo',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/servicios/' . $id, [
                'nombre' => 'Servicio Actualizado',
                'estado' => 'EnEjecucion',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Servicio Actualizado')
            ->assertJsonPath('data.estado', 'EnEjecucion');
    }

    #[Test]
    public function it_deletes_a_servicio(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios', [
                'entidad_id' => $refs['entidad']->id,
                'nombre' => 'Servicio a Eliminar',
                'vr_servicio' => 1000000,
                'estado' => 'Nuevo',
            ]);

        $id = $createResponse->json('data.id');

        $deleteResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/servicios/' . $id);

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('servicios', ['id' => $id]);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/servicios', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_servicio(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/servicios/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_searches_servicios_by_nombre(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        Servicio::factory()->create([
            'entidad_id' => $refs['entidad']->id,
            'nombre' => 'Implementación ERP',
            'vr_servicio' => 25000000,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/servicios?search=ERP');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_filters_by_estado(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/servicios?estado=Nuevo');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
