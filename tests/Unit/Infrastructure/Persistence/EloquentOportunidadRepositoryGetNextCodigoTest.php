<?php

namespace Tests\Unit\Infrastructure\Persistence;

use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Models\Ciudad;
use App\Models\Entidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Oportunidad;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for EloquentOportunidadRepository::getNextCodigo().
 *
 * Covers spec scenarios for the sequential opportunity code:
 *   - "First opportunity of the calendar year" (no records → 001)
 *   - "Counter persists across the semester-1 to semester-2 boundary"
 *   - "Counter resets on calendar year boundary"
 *   - "Soft-deleted codes are counted (no slot reuse)"
 *   - Overflow guard at >999 (throws \OverflowException)
 *   - Argument validation (year 1970..9999, semester 1|2)
 *
 * Tested via the repository directly (NOT via HTTP) because `RefreshDatabase`
 * wraps each test in a single MySQL transaction; HTTP requests within the
 * same test re-use the connection but run inside savepoints that can't see
 * the test's uncommitted inserts (REPEATABLE READ snapshot isolation).
 */
class EloquentOportunidadRepositoryGetNextCodigoTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): OportunidadRepositoryInterface
    {
        return $this->app->make(OportunidadRepositoryInterface::class);
    }

    private function seedCodigo(string $codigo, string $fecha, string $estado = 'Activa'): void
    {
        // Ensure a parent ciudad exists for the entidad FK
        Ciudad::firstOrCreate(
            ['cod_municipio' => '05001'],
            ['nombre' => 'Medellín', 'departamento' => 'Antioquia']
        );

        $entidad = Entidad::first();
        if (! $entidad) {
            $entidad = Entidad::factory()->create(['ciudad_cod' => '05001']);
        }

        $pipelineId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');
        $etapaId = DB::table('pipeline_etapas')
            ->where('pipeline_id', $pipelineId)
            ->where('codigo', 'BORRADOR')
            ->value('id');

        DB::table('oportunidad')->insert([
            'codigo' => $codigo,
            'entidad_id' => $entidad->id,
            'contacto_id' => null,
            'fecha' => $fecha,
            'estado' => $estado,
            'is_latest' => 1,
            'pipeline_id' => $pipelineId,
            'pipeline_etapa_id' => $etapaId,
            'created_at' => $fecha.' 10:00:00',
            'updated_at' => $fecha.' 10:00:00',
        ]);
    }

    // --- Scenario 1: First opportunity of the calendar year ---

    #[Test]
    public function it_returns_001_when_table_is_empty(): void
    {
        $code = $this->repository()->getNextCodigo(2026, 1);

        $this->assertSame('GC-01-2026-001', $code);
    }

    #[Test]
    public function it_pads_semester_to_two_digits(): void
    {
        // Semester 1 → "01", semester 2 → "02"
        $this->assertSame('GC-01-2026-001', $this->repository()->getNextCodigo(2026, 1));
        $this->assertSame('GC-02-2026-001', $this->repository()->getNextCodigo(2026, 2));
    }

    // --- Scenario 2: Counter persists across the semester-1 to semester-2 boundary ---

    #[Test]
    public function it_continues_counter_across_semester_boundary(): void
    {
        $this->seedCodigo('GC-01-2026-162', '2026-06-15');

        $code = $this->repository()->getNextCodigo(2026, 2);

        $this->assertSame('GC-02-2026-163', $code);
    }

    // --- Scenario 3: Counter resets on calendar year boundary ---

    #[Test]
    public function it_resets_counter_on_year_boundary(): void
    {
        $this->seedCodigo('GC-02-2026-999', '2026-12-15');

        $code = $this->repository()->getNextCodigo(2027, 1);

        $this->assertSame('GC-01-2027-001', $code);
    }

    // --- Scenario 4: Soft-deleted codes are counted (no slot reuse) ---

    #[Test]
    public function it_counts_soft_deleted_codes(): void
    {
        $this->seedCodigo('GC-01-2026-100', '2026-02-01');

        // Soft-delete the seed
        Oportunidad::withTrashed()
            ->where('codigo', 'GC-01-2026-100')
            ->update(['deleted_at' => now()]);

        $code = $this->repository()->getNextCodigo(2026, 1);

        // Next code MUST be 101, not 100 (no slot reuse for soft-deleted)
        $this->assertSame('GC-01-2026-101', $code);
    }

    // --- Scenario 5: Atomic counter increment after insert ---

    #[Test]
    public function it_increments_after_each_insert(): void
    {
        // Insert a row between two getNextCodigo calls. The first call
        // returns 001, then we insert it, then the second call must
        // return 002 (not 001) — proving the counter is re-read on
        // every call, not cached at the start of the transaction.
        Ciudad::firstOrCreate(
            ['cod_municipio' => '05001'],
            ['nombre' => 'Medellín', 'departamento' => 'Antioquia']
        );
        $entidad = Entidad::first();
        if (! $entidad) {
            $entidad = Entidad::factory()->create(['ciudad_cod' => '05001']);
        }

        $pipelineId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');
        $etapaId = DB::table('pipeline_etapas')
            ->where('pipeline_id', $pipelineId)
            ->where('codigo', 'BORRADOR')
            ->value('id');

        DB::transaction(function () use ($entidad, $pipelineId, $etapaId) {
            $first = $this->repository()->getNextCodigo(2026, 1);
            $this->assertSame('GC-01-2026-001', $first);

            // Insert the first code so the next read sees it
            DB::table('oportunidad')->insert([
                'codigo' => $first,
                'entidad_id' => $entidad->id,
                'contacto_id' => null,
                'fecha' => '2026-02-15',
                'estado' => 'Activa',
                'is_latest' => 1,
                'pipeline_id' => $pipelineId,
                'pipeline_etapa_id' => $etapaId,
                'created_at' => '2026-02-15 10:00:00',
                'updated_at' => '2026-02-15 10:00:00',
            ]);

            $second = $this->repository()->getNextCodigo(2026, 1);
            $this->assertSame('GC-01-2026-002', $second);
        });
    }

    // --- Overflow guard ---

    #[Test]
    public function it_throws_when_counter_exceeds_999(): void
    {
        $this->seedCodigo('GC-01-2026-999', '2026-06-15');

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Opportunity code counter exceeded 999 for year 2026');

        $this->repository()->getNextCodigo(2026, 1);
    }

    // --- Argument validation ---

    #[Test]
    public function it_rejects_invalid_year(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Year must be 1970..9999');

        $this->repository()->getNextCodigo(1000, 1);
    }

    #[Test]
    public function it_rejects_invalid_semester(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Semester must be 1 or 2');

        $this->repository()->getNextCodigo(2026, 3);
    }
}
