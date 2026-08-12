<?php

namespace Tests\Feature\API;

use App\Application\UseCases\Oportunidad\OportunidadCsvImportUseCase;
use App\Models\Ciudad;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Carbon\Carbon;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OportunidadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PipelineSeeder::class);
    }

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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
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

        // Verify codigo format: GC-{semestre}-{año}-{consecutivo} e.g. GC-01-2026-001
        $this->assertMatchesRegularExpression('/^GC-\d{2}-\d{4}-\d{3}$/', $response->json('data.codigo'));
    }

    #[Test]
    public function it_shows_an_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');

        $showResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/oportunidades/'.$id);

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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');

        $updateResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id, [
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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');

        $deleteResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/oportunidades/'.$id);

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        // Verify the row is actually gone (hard delete, not soft delete)
        $this->assertDatabaseMissing('oportunidad', ['id' => $id]);
    }

    #[Test]
    public function deleting_latest_version_promotes_previous(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        // Create v0 (root)
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);
        $idV0 = $response->json('data.id');

        // Create v1 (latest)
        $versionResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$idV0}/version");
        $idV1 = $versionResponse->json('data.id');

        // Verify v1 is latest
        $this->assertDatabaseHas('oportunidad', [
            'id' => $idV1,
            'is_latest' => 1,
        ]);

        // Delete v1
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/oportunidades/'.$idV1)
            ->assertStatus(200);

        // v1 should be gone
        $this->assertDatabaseMissing('oportunidad', ['id' => $idV1]);

        // v0 should now be promoted to latest
        $this->assertDatabaseHas('oportunidad', [
            'id' => $idV0,
            'is_latest' => 1,
            'parent_id' => null,
            'estado' => 'Activa',
        ]);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_oportunidad(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
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

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', $data);

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', $data);

        $firstCodigo = $first->json('data.codigo');
        $secondCodigo = $second->json('data.codigo');

        $this->assertMatchesRegularExpression('/^GC-\d{2}-\d{4}-\d{3}$/', $firstCodigo);
        $this->assertMatchesRegularExpression('/^GC-\d{2}-\d{4}-\d{3}$/', $secondCodigo);
        $this->assertNotEquals($firstCodigo, $secondCodigo);
    }

    #[Test]
    public function it_can_change_estado_to_ganada(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');

        $updateResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/oportunidades/'.$id, [
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
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/oportunidades', [
                    'entidad_id' => $refs['entidad']->id,
                    'contacto_id' => $refs['contacto']->id,
                    'fecha' => '2026-05-10',
                    'estado' => 'Borrador',
                ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/oportunidades?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(2, $data);
        $this->assertEquals(3, $response->json('data.total'));
    }

    #[Test]
    public function it_creates_a_new_version_of_an_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        // Create base opportunity
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ]);

        $id = $response->json('data.id');
        $originalCodigo = $response->json('data.codigo');

        // Create version
        $versionResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/oportunidades/{$id}/version");

        // Convention A (CSV/legacy, see CrearVersionOportunidadUseCase):
        //   root version = 0, first created version = " v1" with version = 1
        //   the new row IS the latest, so parent_id is null (NOT pointing to root)
        $versionResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.is_latest', true)
            ->assertJsonPath('data.codigo', $originalCodigo.' v1');

        // Verify the original opportunity is now marked is_latest=false and Inactiva
        $original = Oportunidad::find($id);
        $this->assertFalse($original->is_latest);
        $this->assertEquals('Inactiva', $original->estado);
    }

    // --- PR-A1 (#15): Sequential opportunity code generation ---

    /**
     * Spec scenario "First opportunity of the calendar year".
     * With empty table and Carbon::setTestNow in semester 1, first POST returns GC-01-2026-001.
     */
    #[Test]
    public function test_generates_sequential_code_within_year(): void
    {
        Carbon::setTestNow('2026-02-15'); // February 2026 → semester 1
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-02-15',
                'estado' => 'Borrador',
            ]);

        $response->assertStatus(201);
        $this->assertSame('GC-01-2026-001', $response->json('data.codigo'));
    }

    /**
     * Spec scenario "Counter persists across the semester-1 to semester-2 boundary".
     * Last sem-1 code is GC-01-2026-162 → moving to sem-2 must continue at 163, NOT reset to 001.
     */
    #[Test]
    public function test_counter_persists_across_semester_boundary(): void
    {
        // NOTE: This test depends on the same-named unit test
        // (EloquentOportunidadRepositoryGetNextCodigoTest::it_continues_counter_across_semester_boundary)
        // for the actual counter logic. The HTTP-level test below verifies
        // the integration path: the controller delegates to the use case
        // which delegates to the repository. The test seeds the codigo
        // directly so the HTTP request can observe it via the API.
        $token = $this->authenticate();
        $refs = $this->createReferences();

        // Seed: directly insert an oportunidad in semester 1 with codigo ending in 162
        // (DB-level because we need to bypass the generator for this scenario)
        $seedCodigo = 'GC-01-2026-162';
        $pipelineId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');
        $etapaId = DB::table('pipeline_etapas')
            ->where('pipeline_id', $pipelineId)
            ->where('codigo', 'BORRADOR')
            ->value('id');

        DB::table('oportunidad')->insert([
            'codigo' => $seedCodigo,
            'entidad_id' => $refs['entidad']->id,
            'contacto_id' => $refs['contacto']->id,
            'fecha' => '2026-06-15',
            'estado' => 'Activa',
            'is_latest' => true,
            'pipeline_id' => $pipelineId,
            'pipeline_etapa_id' => $etapaId,
            'created_at' => '2026-06-15 10:00:00',
            'updated_at' => '2026-06-15 10:00:00',
        ]);

        // Move clock to semester 2 of the same year
        Carbon::setTestNow('2026-08-15');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2026-08-15',
                'estado' => 'Borrador',
            ]);

        $response->assertStatus(201);
        $this->assertSame('GC-02-2026-163', $response->json('data.codigo'));
    }

    /**
     * Spec scenario "Counter resets on calendar year boundary".
     * Last 2026 code is GC-02-2026-999 → first 2027 code MUST be GC-01-2027-001 (not 1000).
     */
    #[Test]
    public function test_counter_resets_on_year_boundary(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $pipelineId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');
        $etapaId = DB::table('pipeline_etapas')
            ->where('pipeline_id', $pipelineId)
            ->where('codigo', 'BORRADOR')
            ->value('id');

        DB::table('oportunidad')->insert([
            'codigo' => 'GC-02-2026-999',
            'entidad_id' => $refs['entidad']->id,
            'contacto_id' => $refs['contacto']->id,
            'fecha' => '2026-12-15',
            'estado' => 'Activa',
            'is_latest' => true,
            'pipeline_id' => $pipelineId,
            'pipeline_etapa_id' => $etapaId,
            'created_at' => '2026-12-15 10:00:00',
            'updated_at' => '2026-12-15 10:00:00',
        ]);

        Carbon::setTestNow('2027-01-02');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', [
                'entidad_id' => $refs['entidad']->id,
                'contacto_id' => $refs['contacto']->id,
                'fecha' => '2027-01-02',
                'estado' => 'Borrador',
            ]);

        $response->assertStatus(201);
        $this->assertSame('GC-01-2027-001', $response->json('data.codigo'));
    }

    /**
     * Spec scenario "Atomic counter increment under concurrent inserts".
     * Two parallel POSTs dispatched back-to-back MUST get distinct codigos.
     * Marked #[Group('concurrency')] because real DB serialization is hard to
     * simulate reliably in SQLite-in-memory; production MySQL/MariaDB is the
     * environment where this matters.
     */
    #[Test]
    #[Group('concurrency')]
    public function test_concurrent_code_generation_unique(): void
    {
        Carbon::setTestNow('2026-05-10');
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $payload = [
            'entidad_id' => $refs['entidad']->id,
            'contacto_id' => $refs['contacto']->id,
            'fecha' => '2026-05-10',
            'estado' => 'Borrador',
        ];

        // Fire two POSTs in immediate succession against the same connection.
        // With DB::transaction + lockForUpdate, the second request blocks until
        // the first commits and reads the new max — so codigos MUST be distinct.
        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', $payload);

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/oportunidades', $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);

        $firstCodigo = $first->json('data.codigo');
        $secondCodigo = $second->json('data.codigo');

        $this->assertNotSame($firstCodigo, $secondCodigo);
        $this->assertMatchesRegularExpression('/^GC-\d{2}-\d{4}-\d{3}$/', $firstCodigo);
        $this->assertMatchesRegularExpression('/^GC-\d{2}-\d{4}-\d{3}$/', $secondCodigo);
    }

    /**
     * Spec scenario "CSV import rejects malformed codes".
     * Rows with codigo like "GC-1-2026-001" (1-digit semester) MUST be rejected
     * and counted in the import summary's errors bucket, with a structured detail.
     */
    #[Test]
    public function test_csv_import_rejects_malformed_codes(): void
    {
        $useCase = new OportunidadCsvImportUseCase;

        $rows = [
            [
                'codigo' => 'GC-1-2026-001', // malformed: 1-digit semester
                'empresa' => 'Test SA',
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ],
            [
                'codigo' => 'GC-01-26-001', // malformed: 2-digit year
                'empresa' => 'Test SA',
                'fecha' => '2026-05-10',
                'estado' => 'Borrador',
            ],
        ];

        $summary = $useCase->import($rows);

        $this->assertGreaterThanOrEqual(1, $summary['errors'] ?? 0);

        $malformedDetail = collect($summary['details'] ?? [])
            ->firstWhere('reason', 'malformed');

        $this->assertNotNull($malformedDetail, 'Expected a malformed-code detail entry');
        $this->assertSame('GC-1-2026-001', $malformedDetail['codigo']);
    }
}
