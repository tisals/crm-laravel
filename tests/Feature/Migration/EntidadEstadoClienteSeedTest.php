<?php

namespace Tests\Feature\Migration;

use App\Models\App;
use App\Models\Entidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-C: entidad.estado='Cliente' seed migration (REQ-ENT-002, AD-11).
 *
 * SKIPPED at the class level: Commit 5.5 of `tenant-data-model-correction`
 * dropped `entidad.estado` (and `entidad.cliente_desde`). The PR-C
 * seed migration `2026_08_28_000005_create_entidad_estado_audit_and_seed_cliente.php`
 * does `UPDATE entidad SET estado='Cliente' WHERE ...` which now
 * references a column that no longer exists. The migration still
 * creates the `entidad_estado_audit` side table (which is harmless)
 * but its UPDATE step would error on `migrate:fresh`.
 *
 * The semantically-equivalent coverage now lives in:
 *   - `BackfillEntidadRelacionTest`: verifies the backfill from
 *     legacy `estado`/`cliente_desde` into `entidad_relacion`.
 *   - `EntidadRelacionTableTest`: verifies the schema of the pivot
 *     including `frecuencia` / `recurrencia_cada_meses` / `vigencia_meses`.
 *
 * To restore this test, re-run it against the new `entidad_relacion`
 * shape: a "qualifying" entidad (one with `app_entidad.estado='Activo'`)
 * should now have an `entidad_relacion` row with
 * `tipo_relacion='cliente'`, not a `entidad.estado='Cliente'` column
 * write.
 */
class EntidadEstadoClienteSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Class-level skip — see class docblock.
        $this->markTestSkipped(
            'Commit 5.5 dropped entidad.estado; the PR-C seed migration\'s ' .
            'UPDATE step is no longer applicable. Coverage moved to ' .
            'BackfillEntidadRelacionTest.'
        );
    }

    private const SEED_MIGRATION = '2026_08_28_000005_create_entidad_estado_audit_and_seed_cliente';

    /**
     * Helper: seed an `apps` row (the FK target of app_entidad).
     */
    private function createApp(string $slug = 'saas-app'): App
    {
        return App::create([
            'slug' => $slug,
            'nombre' => 'SaaS App for '.$slug,
            'tipo' => 'customer',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);
    }

    /**
     * Helper: attach an app_entidad pivot row via raw DB insert.
     */
    private function attachApp(int $appId, int $entidadId, string $estado): void
    {
        DB::table('app_entidad')->insert([
            'app_id' => $appId,
            'entidad_id' => $entidadId,
            'estado' => $estado,
            'fecha_contrato' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Helper: create a parent entity.
     */
    private function createEntidad(array $overrides = []): Entidad
    {
        return Entidad::create(array_merge([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Entity',
            'identificacion' => 'TEST-'.uniqid(),
            'estado' => 'Activo',
        ], $overrides));
    }

    /**
     * Helper: re-trigger the seed migration against the current fixture
     * data.
     *
     * RefreshDatabase runs the full migration set ONCE during test class
     * setUp, BEFORE the test body creates its fixture rows. So a plain
     * `migrate` call inside the test is a no-op. To exercise the data
     * side-effect of the seed migration, we remove its row from the
     * `migrations` table and re-run. We do NOT drop the audit table —
     * the migration guards `Schema::create` with `hasTable()` so re-run
     * is a clean no-op for the create step.
     */
    private function reRunSeedMigration(): void
    {
        DB::table('migrations')
            ->where('migration', self::SEED_MIGRATION)
            ->delete();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    // ── 1c.3 — qualifying entity gets estado='Cliente' + audit row ──

    #[Test]
    public function qualifying_entity_with_activo_app_entidad_is_marked_cliente(): void
    {
        $app = $this->createApp('saas-activo');
        $entidad = $this->createEntidad(['estado' => 'Activo']);

        // Pre-attach an Activo app_entidad row.
        $this->attachApp($app->id, $entidad->id, 'Activo');

        // Re-run the seed migration against the fixture.
        $this->reRunSeedMigration();

        $reloaded = Entidad::find($entidad->id);
        $this->assertSame(
            'Cliente',
            $reloaded->estado,
            'entidad.estado must become Cliente when app_entidad.estado=Activo'
        );

        $this->assertTrue(
            Schema::hasTable('entidad_estado_audit'),
            'entidad_estado_audit table must exist after seed migration'
        );

        $audit = DB::table('entidad_estado_audit')
            ->where('entidad_id', $entidad->id)
            ->first();

        $this->assertNotNull($audit, 'audit row must capture the change');
        $this->assertSame('Activo', $audit->previous_estado, 'previous_estado must be "Activo"');
        $this->assertSame('Cliente', $audit->new_estado, 'new_estado must be "Cliente"');
    }

    #[Test]
    public function qualifying_entity_with_trial_app_entidad_is_marked_cliente(): void
    {
        // Triangulation: 'Trial' is also a qualifying estado (per REQ-ISCF-004).
        $app = $this->createApp('saas-trial');
        $entidad = $this->createEntidad(['estado' => 'Inactivo']);

        $this->attachApp($app->id, $entidad->id, 'Trial');

        $this->reRunSeedMigration();

        $reloaded = Entidad::find($entidad->id);
        $this->assertSame(
            'Cliente',
            $reloaded->estado,
            'entidad.estado must become Cliente when app_entidad.estado=Trial'
        );

        $audit = DB::table('entidad_estado_audit')
            ->where('entidad_id', $entidad->id)
            ->first();
        $this->assertSame('Inactivo', $audit->previous_estado);
    }

    // ── 1c.4 — idempotency + non-qualifying entities untouched ──

    #[Test]
    public function seed_is_idempotent_second_run_inserts_no_audit_rows(): void
    {
        $app = $this->createApp('saas-idem');
        $entidad = $this->createEntidad(['estado' => 'Activo']);

        $this->attachApp($app->id, $entidad->id, 'Activo');

        // First run against the fixture.
        $this->reRunSeedMigration();

        $auditCountAfterFirst = DB::table('entidad_estado_audit')
            ->where('entidad_id', $entidad->id)
            ->count();

        $this->assertSame(1, $auditCountAfterFirst, 'first run must insert 1 audit row');

        // Second run (still a no-op since the row is already Cliente).
        $this->reRunSeedMigration();

        $auditCountAfterSecond = DB::table('entidad_estado_audit')
            ->where('entidad_id', $entidad->id)
            ->count();

        $this->assertSame(
            1,
            $auditCountAfterSecond,
            'second run must NOT insert additional audit rows (idempotent)'
        );
    }

    #[Test]
    public function non_qualifying_entities_keep_their_estado(): void
    {
        $app = $this->createApp('saas-cancel');

        // Entity A: has app_entidad.estado='Cancelado' — NOT qualifying.
        $entidadCancelado = $this->createEntidad(['estado' => 'Activo']);
        $this->attachApp($app->id, $entidadCancelado->id, 'Cancelado');

        // Entity B: no app_entidad at all — NOT qualifying.
        $entidadOrphan = $this->createEntidad(['estado' => 'Inactivo']);

        $this->reRunSeedMigration();

        $this->assertSame(
            'Activo',
            Entidad::find($entidadCancelado->id)->estado,
            'app_entidad.estado=Cancelado entity must NOT be promoted'
        );
        $this->assertSame(
            'Inactivo',
            Entidad::find($entidadOrphan->id)->estado,
            'entity with no app_entidad must NOT be promoted'
        );

        $this->assertSame(
            0,
            DB::table('entidad_estado_audit')->whereIn('entidad_id', [
                $entidadCancelado->id,
                $entidadOrphan->id,
            ])->count(),
            'no audit rows for non-qualifying entities'
        );
    }

    // ── 1c.5 — down() restores previous_estado + drops audit table ──

    #[Test]
    public function down_restores_previous_estado_from_audit(): void
    {
        $app = $this->createApp('saas-down');
        $entidad = $this->createEntidad(['estado' => 'Activo']);

        $this->attachApp($app->id, $entidad->id, 'Activo');

        $this->reRunSeedMigration();

        // Sanity: promoted.
        $this->assertSame('Cliente', Entidad::find($entidad->id)->estado);

        // Roll back the seed migration.
        $this->artisan('migrate:rollback', ['--step' => 1])->assertExitCode(0);

        // Estado restored to previous value.
        $this->assertSame(
            'Activo',
            Entidad::find($entidad->id)->estado,
            'down() must restore previous_estado from audit'
        );

        // Audit table dropped.
        $this->assertFalse(
            Schema::hasTable('entidad_estado_audit'),
            'entidad_estado_audit must be dropped by down()'
        );
    }
}
