<?php

namespace Tests\Unit\Application\UseCases\Oportunidad;

use App\Application\UseCases\Oportunidad\OportunidadCsvImportUseCase;
use App\Models\Entidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Oportunidad;
use Tests\TestCase;

/**
 * Unit tests for the versioning helpers on OportunidadCsvImportUseCase.
 *
 * Covers:
 *  - parseCodigoVersion: regex for v1/V2/v3/space variants + base (V0)
 *  - postProcessVersions: marks non-latest as Inactiva, sets parent_id, keeps max as Activa
 *  - Idempotency (running twice converges to same state)
 */
class OportunidadVersioningTest extends TestCase
{
    use RefreshDatabase;

    private OportunidadCsvImportUseCase $uc;

    protected function setUp(): void
    {
        parent::setUp();
        // Use reflection to access private parseCodigoVersion
        $this->uc = $this->app->make(OportunidadCsvImportUseCase::class);
    }

    private function parse(string $codigo): array
    {
        $ref = new \ReflectionClass(OportunidadCsvImportUseCase::class);
        $m = $ref->getMethod('parseCodigoVersion');

        return $m->invoke($this->uc, $codigo);
    }

    public function test_parse_base_codigo_returns_version_zero(): void
    {
        [$base, $ver] = $this->parse('GC-01-2026-105');

        $this->assertSame('GC-01-2026-105', $base);
        $this->assertSame(0, $ver);
    }

    public function test_parse_versioned_codigo_with_lowercase_v(): void
    {
        [$base, $ver] = $this->parse('GC-01-2026-105 v2');

        $this->assertSame('GC-01-2026-105', $base);
        $this->assertSame(2, $ver);
    }

    public function test_parse_versioned_codigo_with_uppercase_v(): void
    {
        [$base, $ver] = $this->parse('GC-02-2021-709 V2');

        $this->assertSame('GC-02-2021-709', $base);
        $this->assertSame(2, $ver);
    }

    public function test_parse_codigo_with_multiple_spaces(): void
    {
        [$base, $ver] = $this->parse('GC-01-2022-057   v3');

        $this->assertSame('GC-01-2022-057', $base);
        $this->assertSame(3, $ver);
    }

    public function test_post_process_marks_only_latest_as_activa(): void
    {
        // 4 versions of the same opportunity (no entity FK — direct insert)
        $entidadId = $this->createMinimalEntidad();
        $base = $this->makeOpportunity('TEST-001', $entidadId);
        $v1 = $this->makeOpportunity('TEST-001 v1', $entidadId);
        $v2 = $this->makeOpportunity('TEST-001 v2', $entidadId);
        $v3 = $this->makeOpportunity('TEST-001 v3', $entidadId);

        $this->uc->postProcessVersions();

        // v3 (latest) should be Activa, others Inactiva
        $this->assertSame('Activa', DB::table('oportunidad')->where('id', $v3)->value('estado'));
        $this->assertSame(1, (int) DB::table('oportunidad')->where('id', $v3)->value('is_latest'));
        $this->assertNull(DB::table('oportunidad')->where('id', $v3)->value('parent_id'));
        $this->assertSame(3, (int) DB::table('oportunidad')->where('id', $v3)->value('version'));

        // Older versions: Inactiva, parent pointing to v3
        foreach ([$base, $v1, $v2] as $olderId) {
            $row = DB::table('oportunidad')->where('id', $olderId)->first();
            $this->assertSame('Inactiva', $row->estado, "Op #$olderId should be Inactiva");
            $this->assertSame(0, (int) $row->is_latest);
            $this->assertSame($v3, (int) $row->parent_id);
        }

        // version field reflects parsed value
        $this->assertSame(0, (int) DB::table('oportunidad')->where('id', $base)->value('version'));
        $this->assertSame(1, (int) DB::table('oportunidad')->where('id', $v1)->value('version'));
        $this->assertSame(2, (int) DB::table('oportunidad')->where('id', $v2)->value('version'));
    }

    public function test_post_process_keeps_singleton_as_activa(): void
    {
        // Single opportunity with no version suffix should stay Activa
        $entidadId = $this->createMinimalEntidad();
        $id = $this->makeOpportunity('SOLO-001', $entidadId);

        $this->uc->postProcessVersions();

        $row = DB::table('oportunidad')->where('id', $id)->first();
        $this->assertSame('Activa', $row->estado);
        $this->assertSame(1, (int) $row->is_latest);
        $this->assertNull($row->parent_id);
        $this->assertSame(0, (int) $row->version);
    }

    public function test_post_process_is_idempotent(): void
    {
        $entidadId = $this->createMinimalEntidad();
        $base = $this->makeOpportunity('IDEM-001', $entidadId);
        $v1 = $this->makeOpportunity('IDEM-001 v1', $entidadId);

        // Run twice
        $this->uc->postProcessVersions();
        $firstRun = [
            'base' => DB::table('oportunidad')->where('id', $base)->first(),
            'v1' => DB::table('oportunidad')->where('id', $v1)->first(),
        ];
        $this->uc->postProcessVersions();
        $secondRun = [
            'base' => DB::table('oportunidad')->where('id', $base)->first(),
            'v1' => DB::table('oportunidad')->where('id', $v1)->first(),
        ];

        // Should be identical
        $this->assertEquals($firstRun['base']->estado, $secondRun['base']->estado);
        $this->assertEquals($firstRun['base']->is_latest, $secondRun['base']->is_latest);
        $this->assertEquals($firstRun['base']->parent_id, $secondRun['base']->parent_id);

        $this->assertSame('Activa', $secondRun['v1']->estado);
        $this->assertSame(1, (int) $secondRun['v1']->is_latest);
    }

    public function test_dashboard_filter_excludes_superseded_versions(): void
    {
        $entidadId = $this->createMinimalEntidad();
        $this->makeOpportunity('AGG-001', $entidadId);
        $this->makeOpportunity('AGG-001 v1', $entidadId);
        $this->makeOpportunity('AGG-001 v2', $entidadId);

        $this->uc->postProcessVersions();

        // After post-process: only v2 is is_latest=true
        $activeCount = DB::table('oportunidad')
            ->where('codigo', 'like', 'AGG-001%')
            ->where('is_latest', true)
            ->count();
        $this->assertSame(1, $activeCount);

        // Model scope works too
        $active = Oportunidad::latestActiva()->where('codigo', 'like', 'AGG-001%')->count();
        $this->assertSame(1, $active);
    }

    private function createMinimalEntidad(): int
    {
        // Direct insert — avoids Entidad factory which requires ciudad seeded.
        return DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => 'TEST-'.uniqid(),
            'nombre' => 'Test Entity '.uniqid(),
            'estado' => 'Activo',
            'linea_negocio' => 'TEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOpportunity(string $codigo, int $entidadId): int
    {
        return DB::table('oportunidad')->insertGetId([
            'codigo' => $codigo,
            'entidad_id' => $entidadId,
            'fecha' => '2026-05-01',
            'estado' => 'Activa',
            'version' => 1,
            'is_latest' => 1,
            'parent_id' => null,
            'pipeline_id' => null,
            'pipeline_etapa_id' => null,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
