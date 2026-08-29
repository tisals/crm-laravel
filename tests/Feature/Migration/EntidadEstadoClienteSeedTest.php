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
 * The seed migration runs an UPDATE that promotes entities with active
 * app_entidad rows to estado='Cliente'. A side-table audit captures the
 * previous estado for safe rollback (AD-11).
 *
 * Strict TDD: every test asserts real behaviour — qualification rule,
 * idempotency, down() rollback from audit, schema persistence of audit
 * columns. No smoke tests, no trivial assertions.
 *
 * RED tasks 1c.3, 1c.4, 1c.5 fail before any production code ships.
 * GREEN task 1c.10 makes them pass.
 */
class EntidadEstadoClienteSeedTest extends TestCase
{
    use RefreshDatabase;

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

    // ── 1c.3 — qualifying entity gets estado='Cliente' + audit row ──

    #[Test]
    public function qualifying_entity_with_activo_app_entidad_is_marked_cliente(): void
    {
        $app = $this->createApp('saas-activo');
        $entidad = $this->createEntidad(['estado' => 'Activo']);

        // Pre-attach an Activo app_entidad row.
        $this->attachApp($app->id, $entidad->id, 'Activo');

        // Run the seed migration on top of a fresh DB (RefreshDatabase
        // already ran all up-to-date migrations; re-run the seed migration
        // by calling it through artisan).
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

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

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

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

        // First run (already done by RefreshDatabase setUp).
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $auditCountAfterFirst = DB::table('entidad_estado_audit')
            ->where('entidad_id', $entidad->id)
            ->count();

        $this->assertSame(1, $auditCountAfterFirst, 'first run must insert 1 audit row');

        // Second run (still a no-op since the row is already Cliente).
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

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

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

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

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

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