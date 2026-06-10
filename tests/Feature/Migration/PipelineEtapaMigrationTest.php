<?php

namespace Tests\Feature\Migration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PipelineEtapaMigrationTest extends TestCase
{
    private int $cotizacionId;

    private int $borradorEtapaId;

    private int $firstEtapaId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create minimal schema FIRST (DDL auto-commits in MariaDB, so
        //    it must happen BEFORE we start our data transaction).
        $this->ensureSchema();

        // 2. Now begin transaction for data rollback between tests.
        DB::beginTransaction();

        // 3. Seed COTIZACION and RECUPERACION pipelines with etapas
        $this->seedPipelines();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * Ensure required tables exist (idempotent schema creation).
     */
    private function ensureSchema(): void
    {
        if (! Schema::hasTable('pipelines')) {
            Schema::create('pipelines', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 100);
                $table->string('codigo', 50)->unique();
                $table->boolean('habilitado')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pipeline_etapas')) {
            Schema::create('pipeline_etapas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pipeline_id')->constrained('pipelines')->onDelete('cascade');
                $table->string('nombre', 100);
                $table->integer('orden')->default(0);
                $table->boolean('habilitado')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('entidad')) {
            Schema::create('entidad', function (Blueprint $table) {
                $table->id();
                $table->string('tipo_persona', 20);
                $table->string('nombre', 200);
                $table->string('identificacion', 50)->nullable();
                $table->string('estado', 20)->default('Activo');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('oportunidad')) {
            Schema::create('oportunidad', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 50);
                $table->foreignId('entidad_id')->constrained('entidad');
                $table->foreignId('pipeline_id')->nullable()->constrained('pipelines')->onDelete('set null');
                $table->foreignId('pipeline_etapa_id')->nullable()->constrained('pipeline_etapas')->onDelete('set null');
                $table->date('fecha');
                $table->string('estado', 50)->default('Activa');
                $table->string('observaciones', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        } elseif (! Schema::hasColumn('oportunidad', 'pipeline_etapa_id')) {
            // Table exists from partial migrate:fresh — add missing pipeline columns
            Schema::table('oportunidad', function (Blueprint $table) {
                $table->foreignId('pipeline_id')->nullable()->constrained('pipelines')->onDelete('set null');
                $table->foreignId('pipeline_etapa_id')->nullable()->constrained('pipeline_etapas')->onDelete('set null');
            });
        }

        // If estado column is ENUM type (from old migration), change it to string
        // so we can insert arbitrary legacy estado values like 'cotizacion'.
        $this->ensureEstadoIsString();
    }

    /**
     * Change estado column to string if it's still an ENUM.
     */
    private function ensureEstadoIsString(): void
    {
        $columnInfo = DB::select("SHOW COLUMNS FROM `oportunidad` WHERE Field = 'estado'");
        if (! empty($columnInfo) && str_starts_with($columnInfo[0]->Type, 'enum(')) {
            Schema::table('oportunidad', function (Blueprint $table) {
                $table->string('estado', 50)->default('Activa')->change();
            });
        }
    }

    /**
     * Seed COTIZACION and RECUPERACION pipelines with their etapas.
     */
    private function seedPipelines(): void
    {
        $this->cotizacionId = DB::table('pipelines')->insertGetId([
            'nombre' => 'Cotización',
            'codigo' => 'COTIZACION',
            'habilitado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $etapas = [
            ['nombre' => 'Borrador',        'orden' => 1],
            ['nombre' => 'Enviada',         'orden' => 2],
            ['nombre' => 'Aceptada',        'orden' => 3],
            ['nombre' => 'Rechazada',       'orden' => 4],
            ['nombre' => 'Ganada',          'orden' => 5],
            ['nombre' => 'Perdida',         'orden' => 6],
        ];

        foreach ($etapas as $i => $etapa) {
            $id = DB::table('pipeline_etapas')->insertGetId([
                'pipeline_id' => $this->cotizacionId,
                'nombre' => $etapa['nombre'],
                'orden' => $etapa['orden'],
                'habilitado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($i === 0) {
                $this->borradorEtapaId = $id;
                $this->firstEtapaId = $id;
            }
        }

        // Seed RECUPERACION pipeline with etapas
        $recuperacionId = DB::table('pipelines')->insertGetId([
            'nombre' => 'Recuperación',
            'codigo' => 'RECUPERACION',
            'habilitado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $recuperacionEtapas = [
            ['nombre' => 'Inicio',              'orden' => 1],
            ['nombre' => 'Con Cita',            'orden' => 2],
            ['nombre' => 'En Negociación',      'orden' => 3],
            ['nombre' => 'Aprobado',            'orden' => 4],
            ['nombre' => 'Rechazado',           'orden' => 5],
        ];

        foreach ($recuperacionEtapas as $etapa) {
            DB::table('pipeline_etapas')->insertGetId([
                'pipeline_id' => $recuperacionId,
                'nombre' => $etapa['nombre'],
                'orden' => $etapa['orden'],
                'habilitado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createEntidad(): int
    {
        return DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Natural',
            'nombre' => 'Migration Test Entity',
            'identificacion' => 'ID-'.rand(100000, 999999),
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOportunidad(string $estado, ?int $pipelineEtapaId = null): int
    {
        return DB::table('oportunidad')->insertGetId([
            'codigo' => 'MIG-TEST-'.rand(10000, 99999),
            'entidad_id' => $this->createEntidad(),
            'fecha' => now()->format('Y-m-d'),
            'estado' => $estado,
            'pipeline_etapa_id' => $pipelineEtapaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Manually run the new migration's up() method.
     */
    private function runMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_06_07_000000_assign_pipeline_etapa_to_existing_oportunidades.php'
        );
        $migration->up();
    }

    // ─── Test: Happy path — migrates estado to pipeline_etapa_id ────────

    #[Test]
    public function it_migrates_estado_to_pipeline_etapa_id(): void
    {
        $id = $this->createOportunidad(estado: 'cotizacion');

        $this->runMigration();

        $row = DB::table('oportunidad')->find($id);
        $this->assertNotNull($row->pipeline_etapa_id);
        $this->assertEquals($this->borradorEtapaId, $row->pipeline_etapa_id);
        $this->assertEquals($this->cotizacionId, $row->pipeline_id);
    }

    // ─── Test: Idempotent — second run does nothing ────────────────────

    #[Test]
    public function it_is_idempotent(): void
    {
        $id = $this->createOportunidad(estado: 'prospecto');

        $this->runMigration();

        $row1 = DB::table('oportunidad')->find($id);
        $this->assertNotNull($row1->pipeline_etapa_id);

        // Run second time
        $this->runMigration();

        $row2 = DB::table('oportunidad')->find($id);
        $this->assertEquals($row1->pipeline_etapa_id, $row2->pipeline_etapa_id);
    }

    // ─── Test: Unmatched estado defaults to first etapa ────────────────

    #[Test]
    public function it_defaults_unmatched_estado_to_first_etapa(): void
    {
        $id = $this->createOportunidad(estado: 'UnknownValue');

        $this->runMigration();

        $row = DB::table('oportunidad')->find($id);
        $this->assertNotNull($row->pipeline_etapa_id);
        $this->assertEquals($this->firstEtapaId, $row->pipeline_etapa_id);
    }

    // ─── Test: Already migrated rows are skipped ────────────────────────

    #[Test]
    public function it_skips_already_migrated_rows(): void
    {
        $otherEtapaId = DB::table('pipeline_etapas')
            ->where('pipeline_id', $this->cotizacionId)
            ->where('nombre', 'Ganada')
            ->value('id');

        $id = $this->createOportunidad(
            estado: 'ganado',
            pipelineEtapaId: $otherEtapaId,
        );

        $this->runMigration();

        $row = DB::table('oportunidad')->find($id);
        $this->assertEquals($otherEtapaId, $row->pipeline_etapa_id);
    }
}
