<?php

namespace Tests\Feature\API;

use App\Events\PipelineEtapaChanged;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\CRM\Models\Oportunidad;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Models\PipelineEtapa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BulkMoveOportunidadesTest extends TestCase
{
    use RefreshDatabase;

    private array $auth;

    private Pipeline $pipeline;

    private PipelineEtapa $etapaOrigen;

    private PipelineEtapa $etapaDestino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authenticate();

        $this->pipeline = Pipeline::factory()->create([
            'nombre' => 'Bulk Move Pipeline',
            'codigo' => 'BULK-TEST',
        ]);

        $this->etapaOrigen = PipelineEtapa::factory()->forPipeline($this->pipeline)->create([
            'nombre' => 'Etapa Origen',
            'orden' => 1,
        ]);

        $this->etapaDestino = PipelineEtapa::factory()->forPipeline($this->pipeline)->create([
            'nombre' => 'Etapa Destino',
            'orden' => 2,
        ]);
    }

    protected function seeder(): string|false
    {
        return false;
    }

    private function authenticate(): array
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

        $token = $usuario->createToken('test-token')->plainTextToken;

        return ['token' => $token, 'usuario' => $usuario, 'rol' => $rol];
    }

    private function withAuth(): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->auth['token']);
    }

    private function createEntidad(): int
    {
        return DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Natural',
            'nombre' => 'Test Entity',
            'identificacion' => 'ID-'.rand(100000, 999999),
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOportunidad(int $entidadId, int $etapaId, string $codigo): int
    {
        return DB::table('oportunidad')->insertGetId([
            'codigo' => $codigo,
            'entidad_id' => $entidadId,
            'pipeline_id' => $this->pipeline->id,
            'pipeline_etapa_id' => $etapaId,
            'fecha' => now()->format('Y-m-d'),
            'estado' => 'Activa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── Test: Happy path — moves multiple oportunidades ───────────────

    #[Test]
    public function it_moves_multiple_oportunidades(): void
    {
        $entidadId = $this->createEntidad();
        $id1 = $this->createOportunidad($entidadId, $this->etapaOrigen->id, 'BULK-001');
        $id2 = $this->createOportunidad($entidadId, $this->etapaOrigen->id, 'BULK-002');
        $id3 = $this->createOportunidad($entidadId, $this->etapaOrigen->id, 'BULK-003');

        $response = $this->withAuth()->postJson('/api/v1/oportunidades/bulk-move-pipeline', [
            'oportunidad_ids' => [$id1, $id2, $id3],
            'target_pipeline_etapa_id' => $this->etapaDestino->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.moved_count', 3)
            ->assertJsonStructure(['data' => ['moved_count', 'oportunidad_ids']]);

        $this->assertEquals($this->etapaDestino->id, Oportunidad::find($id1)->pipeline_etapa_id);
        $this->assertEquals($this->etapaDestino->id, Oportunidad::find($id2)->pipeline_etapa_id);
        $this->assertEquals($this->etapaDestino->id, Oportunidad::find($id3)->pipeline_etapa_id);
    }

    // ─── Test: Rollback on invalid ID ──────────────────────────────────

    #[Test]
    public function it_rolls_back_on_invalid_id(): void
    {
        $entidadId = $this->createEntidad();
        $id1 = $this->createOportunidad($entidadId, $this->etapaOrigen->id, 'BULK-010');

        $response = $this->withAuth()->postJson('/api/v1/oportunidades/bulk-move-pipeline', [
            'oportunidad_ids' => [$id1, 99999],
            'target_pipeline_etapa_id' => $this->etapaDestino->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['invalid_ids']);

        // Verify the original oportunidad was NOT moved (rollback)
        $oportunidad = Oportunidad::find($id1);
        $this->assertEquals($this->etapaOrigen->id, $oportunidad->pipeline_etapa_id);
    }

    // ─── Test: Fires event per oportunidad ─────────────────────────────

    #[Test]
    public function it_fires_event_per_oportunidad(): void
    {
        Event::fake([PipelineEtapaChanged::class]);

        $entidadId = $this->createEntidad();
        $id1 = $this->createOportunidad($entidadId, $this->etapaOrigen->id, 'BULK-020');
        $id2 = $this->createOportunidad($entidadId, $this->etapaOrigen->id, 'BULK-021');
        $id3 = $this->createOportunidad($entidadId, $this->etapaOrigen->id, 'BULK-022');

        $this->withAuth()->postJson('/api/v1/oportunidades/bulk-move-pipeline', [
            'oportunidad_ids' => [$id1, $id2, $id3],
            'target_pipeline_etapa_id' => $this->etapaDestino->id,
        ]);

        Event::assertDispatched(PipelineEtapaChanged::class, 3);
    }

    // ─── Test: Rejects without bulk-move permission ────────────────────

    #[Test]
    public function it_rejects_without_bulk_move_permission(): void
    {
        // Create a Rol that has some permissions but NOT 'oportunidades.bulk-move'
        $rol = Rol::create(['nombre' => 'Sin Bulk Move', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'oportunidades.index']);

        $usuario = Usuario::create([
            'nombre' => 'Restricted User',
            'email' => 'restricted@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $token = $usuario->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades/bulk-move-pipeline', [
                'oportunidad_ids' => [1],
                'target_pipeline_etapa_id' => 1,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
