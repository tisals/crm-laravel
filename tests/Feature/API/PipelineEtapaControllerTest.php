<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\PipelineEtapa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineEtapaControllerTest extends TestCase
{
    use RefreshDatabase;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authenticate();
    }

    protected function seeder(): string|false
    {
        return DatabaseSeeder::class;
    }

    private function authenticate(): array
    {
        // El seeder ya crea roles y permisos, solo necesitamos un usuario
        $usuario = Usuario::where('email', 'admin@test.com')->first();
        if (! $usuario) {
            // Si el seeder no lo creó, lo creamos manualmente
            $rol = Rol::where('nombre', 'Admin')->first();
            if (! $rol) {
                $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
                Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);
            }

            $usuario = Usuario::create([
                'nombre' => 'Admin User',
                'email' => 'admin@test.com',
                'password_hash' => bcrypt('password123'),
                'rol_id' => $rol->id,
                'estado' => 'Activo',
            ]);
        }

        $token = $usuario->createToken('test-token')->plainTextToken;

        return ['token' => $token, 'usuario' => $usuario];
    }

    private function withAuth(): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->auth['token']);
    }

    // ─── List etapas for pipeline ─────────────────────────────────────

    #[Test]
    public function it_can_list_etapas_for_pipeline(): void
    {
        $pipeline = Pipeline::factory()->create();
        PipelineEtapa::factory()->forPipeline($pipeline)->count(3)->create();

        $response = $this->withAuth()->getJson("/api/v1/pipelines/{$pipeline->id}/etapas");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    // ─── Create etapa ─────────────────────────────────────────────────

    #[Test]
    public function it_can_create_etapa(): void
    {
        $pipeline = Pipeline::factory()->create();
        $payload = ['nombre' => 'Nueva Etapa'];

        $response = $this->withAuth()->postJson(
            "/api/v1/pipelines/{$pipeline->id}/etapas",
            $payload,
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Nueva Etapa')
            ->assertJsonPath('data.pipeline_id', $pipeline->id)
            ->assertJsonStructure(['data' => ['id', 'pipeline_id', 'nombre', 'orden', 'habilitado']]);
    }

    #[Test]
    public function it_create_validates_required_nombre(): void
    {
        $pipeline = Pipeline::factory()->create();

        $response = $this->withAuth()->postJson(
            "/api/v1/pipelines/{$pipeline->id}/etapas",
            [],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nombre']);
    }

    // ─── Show etapa ───────────────────────────────────────────────────

    #[Test]
    public function it_can_show_etapa(): void
    {
        $pipeline = Pipeline::factory()->create();
        $etapa = PipelineEtapa::factory()->forPipeline($pipeline)->create([
            'nombre' => 'Mi Etapa',
            'orden' => 1,
        ]);

        $response = $this->withAuth()->getJson("/api/v1/pipelines/etapas/{$etapa->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Mi Etapa')
            ->assertJsonPath('data.orden', 1);
    }

    #[Test]
    public function it_show_returns_404_when_not_found(): void
    {
        $response = $this->withAuth()->getJson('/api/v1/pipelines/etapas/99999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── Update etapa ─────────────────────────────────────────────────

    #[Test]
    public function it_can_update_etapa(): void
    {
        $pipeline = Pipeline::factory()->create();
        $etapa = PipelineEtapa::factory()->forPipeline($pipeline)->create([
            'nombre' => 'Original',
        ]);

        $response = $this->withAuth()->putJson(
            "/api/v1/pipelines/etapas/{$etapa->id}",
            ['nombre' => 'Actualizado'],
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Actualizado');
    }

    // ─── Delete etapa ─────────────────────────────────────────────────

    #[Test]
    public function it_can_delete_etapa(): void
    {
        $pipeline = Pipeline::factory()->create();
        $etapa = PipelineEtapa::factory()->forPipeline($pipeline)->create();

        $response = $this->withAuth()->deleteJson("/api/v1/pipelines/etapas/{$etapa->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('pipeline_etapas', ['id' => $etapa->id]);
    }

    // ─── Reorder etapas ───────────────────────────────────────────────

    #[Test]
    public function it_can_reorder_etapas(): void
    {
        $pipeline = Pipeline::factory()->create();
        $etapa1 = PipelineEtapa::factory()->forPipeline($pipeline)->atOrden(1)->create();
        $etapa2 = PipelineEtapa::factory()->forPipeline($pipeline)->atOrden(2)->create();
        $etapa3 = PipelineEtapa::factory()->forPipeline($pipeline)->atOrden(3)->create();

        $response = $this->withAuth()->putJson(
            "/api/v1/pipelines/{$pipeline->id}/etapas/reorder",
            ['ordered_ids' => [$etapa3->id, $etapa1->id, $etapa2->id]],
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_reorder_validates_cross_pipeline(): void
    {
        $pipeline1 = Pipeline::factory()->create();
        $pipeline2 = Pipeline::factory()->create();

        $etapa1 = PipelineEtapa::factory()->forPipeline($pipeline1)->create();
        $etapa2 = PipelineEtapa::factory()->forPipeline($pipeline2)->create();

        $response = $this->withAuth()->putJson(
            "/api/v1/pipelines/{$pipeline1->id}/etapas/reorder",
            ['ordered_ids' => [$etapa1->id, $etapa2->id]],
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
