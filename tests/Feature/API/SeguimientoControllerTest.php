<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeguimientoControllerTest extends TestCase
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
        $contacto = Contacto::factory()->create(['entidad_id' => $entidad->id]);
        $oportunidad = Oportunidad::create([
            'codigo' => 'COT-000001',
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'fecha' => '2026-05-10',
            'estado' => 'Borrador',
        ]);

        return ['entidad' => $entidad, 'contacto' => $contacto, 'oportunidad' => $oportunidad];
    }

    #[Test]
    public function it_lists_seguimientos(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/seguimientos');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_a_seguimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'entidad_id' => $refs['entidad']->id,
                'tipo' => 'Llamada',
                'fecha' => '2026-05-10',
                'hora' => '10:00:00',
                'notas' => 'Test seguimiento',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo', 'Llamada')
            ->assertJsonPath('data.fecha', '2026-05-10')
            ->assertJsonPath('data.estado', 'Pendiente')
            ->assertJsonPath('data.notas', 'Test seguimiento');
    }

    #[Test]
    public function it_shows_a_seguimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'tipo' => 'Correo',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/seguimientos/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id);
    }

    #[Test]
    public function it_updates_a_seguimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'tipo' => 'Nota',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/seguimientos/' . $id, [
                'estado' => 'Completado',
                'notas' => 'Completed follow-up',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'Completado')
            ->assertJsonPath('data.notas', 'Completed follow-up');
    }

    #[Test]
    public function it_deletes_a_seguimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'tipo' => 'Otro',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);

        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/seguimientos/' . $id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_seguimiento(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/seguimientos/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_filters_by_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'tipo' => 'Reunion',
                'fecha' => '2026-05-10',
                'estado' => 'Completado',
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/oportunidades/{$refs['oportunidad']->id}/seguimientos");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
    }

    #[Test]
    public function it_filters_by_entidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', [
                'entidad_id' => $refs['entidad']->id,
                'tipo' => 'Nota',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/entidades/{$refs['entidad']->id}/seguimientos");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
    }

    #[Test]
    public function it_filters_by_contacto(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', [
                'contacto_id' => $refs['contacto']->id,
                'tipo' => 'Llamada',
                'fecha' => '2026-05-10',
                'estado' => 'Completado',
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/contactos/{$refs['contacto']->id}/seguimientos");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
    }

    #[Test]
    public function it_creates_without_oportunidad(): void
    {
        $token = $this->authenticate();

        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/seguimientos', [
                'entidad_id' => $entidad->id,
                'tipo' => 'Nota',
                'fecha' => '2026-05-10',
                'notas' => 'Nota sobre entidad sin oportunidad',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entidad_id', $entidad->id)
            ->assertJsonPath('data.tipo', 'Nota');
    }
}
