<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\Contacto;
use App\Models\Ciudad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OportunidadControllerTest extends TestCase
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

        return ['entidad' => $entidad, 'contacto' => $contacto];
    }

    #[Test]
    public function it_lists_oportunidades(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/oportunidades');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_an_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'fuente_canal' => 'Web',
                'estado' => 'Borrador',
                'observaciones' => 'Test observations',
                'validez_oferta' => 30,
                'tiempo_entrega' => '15 días',
                'forma_pago' => 'Crédito 30 días',
                'garantia' => '12 meses',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entidad_id', $refs['entidad']->id)
            ->assertJsonPath('data.contacto_id', $refs['contacto']->id);

        // Verify codigo format: GC-{semestre}-{año}-{consecutivo} e.g. GC-1-2026-001
        $this->assertMatchesRegularExpression('/^GC-\d-\d{4}-\d{3}$/', $response->json('data.codigo'));
    }

    #[Test]
    public function it_shows_an_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');

        $showResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/oportunidades/' . $id);

        $showResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.entidad_nombre', $refs['entidad']->nombre)
            ->assertJsonPath('data.entidad_identificacion', $refs['entidad']->identificacion);
    }

    #[Test]
    public function it_updates_an_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');

        $updateResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/oportunidades/' . $id, [
                'estado' => 'Enviada',
                'observaciones' => 'Updated observations',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'Enviada')
            ->assertJsonPath('data.observaciones', 'Updated observations');
    }

    #[Test]
    public function it_deletes_an_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');

        $deleteResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/oportunidades/' . $id);

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_oportunidad(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/oportunidades/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_generates_sequential_codigos(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $data = [
            'entidad_id' => $refs['entidad']->id,
            'contacto_id' => $refs['contacto']->id,
            'fecha' => '2026-05-10',
            'estado' => 'Borrador',
        ];

        $first = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', $data);

        $second = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', $data);

        $firstCodigo = $first->json('data.codigo');
        $secondCodigo = $second->json('data.codigo');

        $this->assertMatchesRegularExpression('/^GC-\d-\d{4}-\d{3}$/', $firstCodigo);
        $this->assertMatchesRegularExpression('/^GC-\d-\d{4}-\d{3}$/', $secondCodigo);
        $this->assertNotEquals($firstCodigo, $secondCodigo);
    }

    #[Test]
    public function it_can_change_estado_to_ganada(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');

        $updateResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/oportunidades/' . $id, [
                'estado' => 'Ganada',
            ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'Ganada');
    }

    #[Test]
    public function it_paginates_results(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        // Create 3 oportunidades
        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/api/v1/oportunidades', [
                    'entidad_id' => $refs['entidad']->id,
                    'contacto_id' => $refs['contacto']->id,
                    'fecha' => '2026-05-10',
                    'estado' => 'Borrador',
                ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/oportunidades?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(2, $data);
        $this->assertEquals(3, $response->json('data.total'));
    }
}
