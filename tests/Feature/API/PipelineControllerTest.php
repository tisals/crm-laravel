<?php

namespace Tests\Feature\API;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CRM\Models\Pipeline;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineControllerTest extends TestCase
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
        return false;
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

    // ─── List ──────────────────────────────────────────────────────────

    #[Test]
    public function it_can_list_pipelines(): void
    {
        Pipeline::factory()->count(2)->create();

        $response = $this->withAuth()->getJson('/api/v1/pipelines');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    // ─── Create ────────────────────────────────────────────────────────

    #[Test]
    public function it_can_create_a_pipeline(): void
    {
        $payload = [
            'nombre' => 'Pipeline Test',
            'codigo' => 'TEST-001',
        ];

        $response = $this->withAuth()->postJson('/api/v1/pipelines', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Pipeline Test')
            ->assertJsonPath('data.codigo', 'TEST-001')
            ->assertJsonStructure(['data' => ['id', 'nombre', 'codigo', 'habilitado']]);
    }

    #[Test]
    public function create_validates_required_fields(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/pipelines', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nombre', 'codigo']);
    }

    #[Test]
    public function create_validates_unique_codigo(): void
    {
        Pipeline::factory()->create(['codigo' => 'DUPLICADO']);

        $response = $this->withAuth()->postJson('/api/v1/pipelines', [
            'nombre' => 'Otro',
            'codigo' => 'DUPLICADO',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    // ─── Show ──────────────────────────────────────────────────────────

    #[Test]
    public function it_can_show_a_pipeline(): void
    {
        $pipeline = Pipeline::factory()->create([
            'nombre' => 'Mi Pipeline',
            'codigo' => 'MI-001',
        ]);

        $response = $this->withAuth()->getJson("/api/v1/pipelines/{$pipeline->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Mi Pipeline')
            ->assertJsonPath('data.codigo', 'MI-001');
    }

    #[Test]
    public function show_returns_404_when_not_found(): void
    {
        $response = $this->withAuth()->getJson('/api/v1/pipelines/99999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ─── Update ────────────────────────────────────────────────────────

    #[Test]
    public function it_can_update_a_pipeline(): void
    {
        $pipeline = Pipeline::factory()->create([
            'nombre' => 'Original',
            'codigo' => 'ORIG',
        ]);

        $response = $this->withAuth()->putJson("/api/v1/pipelines/{$pipeline->id}", [
            'nombre' => 'Actualizado',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Actualizado');
    }

    // ─── Delete ────────────────────────────────────────────────────────

    #[Test]
    public function it_can_delete_a_pipeline(): void
    {
        $pipeline = Pipeline::factory()->create();

        $response = $this->withAuth()->deleteJson("/api/v1/pipelines/{$pipeline->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('pipelines', ['id' => $pipeline->id]);
    }

    #[Test]
    public function delete_returns_404_when_not_found(): void
    {
        $response = $this->withAuth()->deleteJson('/api/v1/pipelines/99999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
