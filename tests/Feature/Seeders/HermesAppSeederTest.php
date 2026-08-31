<?php

namespace Tests\Feature\Seeders;

use App\Models\App;
use Database\Seeders\HermesAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-D (Phase 2): HermesAppSeeder (REQ-HPBN-002, REQ-HPBN-003, REQ-HPBN-004).
 *
 * The seeder creates:
 *   - exactly 1 `apps` row with slug='hermes', tipo='customer', auth_type='sanctum', activo=true
 *   - exactly 5 `app_entidad` rows with perfil in the 5 canonical slugs:
 *     setter-safe-health, setter-tis, setter-alejandro, marketing-sailus, sst-support-safe-health
 *
 * It is idempotent (re-running does NOT duplicate rows) and MUST NOT touch
 * pre-existing `app_entidad` rows for other apps (perfil must remain NULL on them).
 *
 * Strict TDD: every test asserts real DB state via raw queries. No trivial
 * assertions, no mocks. RED tasks 2.1-2.4 fail before any production code.
 * GREEN task 2.7 makes them pass.
 */
class HermesAppSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_PERFILES = [
        'marketing-sailus',
        'setter-alejandro',
        'setter-safe-health',
        'setter-tis',
        'sst-support-safe-health',
    ];

    /**
     * Helper: seed the hermes app via the production seeder class.
     */
    private function runHermesSeeder(): void
    {
        $this->artisan('db:seed', ['--class' => HermesAppSeeder::class])
            ->assertExitCode(0);
    }

    // ── 2.1 — exactly 1 apps row with canonical hermes attributes ─────

    #[Test]
    public function seeder_creates_exactly_one_hermes_apps_row(): void
    {
        $this->runHermesSeeder();

        $count = DB::table('apps')->where('slug', 'hermes')->count();

        $this->assertSame(
            1,
            $count,
            'Seeder MUST create exactly 1 apps row with slug=hermes'
        );

        $row = DB::table('apps')->where('slug', 'hermes')->first();

        $this->assertSame(
            'customer',
            $row->tipo,
            'hermes apps row MUST have tipo=customer'
        );
        $this->assertSame(
            'sanctum',
            $row->auth_type,
            'hermes apps row MUST have auth_type=sanctum'
        );
        $this->assertTrue(
            (bool) $row->activo,
            'hermes apps row MUST have activo=true'
        );
        $this->assertNotEmpty(
            $row->nombre,
            'hermes apps row MUST have a non-empty nombre'
        );
    }

    // ── 2.2 — exactly 5 app_entidad rows with the 5 canonical perfils ──

    #[Test]
    public function seeder_creates_five_app_entidad_profile_rows(): void
    {
        $this->runHermesSeeder();

        $hermesId = DB::table('apps')->where('slug', 'hermes')->value('id');
        $this->assertNotNull($hermesId, 'Hermes apps row must exist');

        $perfiles = DB::table('app_entidad')
            ->where('app_id', $hermesId)
            ->whereNotNull('perfil')
            ->pluck('perfil')
            ->sort()
            ->values()
            ->toArray();

        $this->assertSame(
            self::EXPECTED_PERFILES,
            $perfiles,
            'The 5 perfil slugs MUST exactly match the canonical Hermes profiles'
        );

        // Every row points to a non-null entidad (canonical brand entity) and is active.
        $rows = DB::table('app_entidad')
            ->where('app_id', $hermesId)
            ->whereNotNull('perfil')
            ->get();

        foreach ($rows as $row) {
            $this->assertNotNull(
                $row->entidad_id,
                "Hermes profile row ({$row->perfil}) MUST link to an entidad_id"
            );
            $this->assertSame(
                'Activo',
                $row->estado,
                "Hermes profile row ({$row->perfil}) MUST be in estado=Activo"
            );
        }
    }

    // ── 2.3 — seeder is idempotent (running twice keeps counts stable) ─

    #[Test]
    public function seeder_is_idempotent_running_twice_keeps_counts_stable(): void
    {
        $this->runHermesSeeder();
        $this->runHermesSeeder();

        $hermesId = DB::table('apps')->where('slug', 'hermes')->value('id');

        $this->assertSame(
            1,
            DB::table('apps')->where('slug', 'hermes')->count(),
            'Idempotency: 2nd run MUST NOT duplicate the apps row'
        );

        $profileCount = DB::table('app_entidad')
            ->where('app_id', $hermesId)
            ->whereNotNull('perfil')
            ->count();

        $this->assertSame(
            5,
            $profileCount,
            'Idempotency: 2nd run MUST keep exactly 5 profile rows'
        );

        // Perfil set unchanged.
        $perfiles = DB::table('app_entidad')
            ->where('app_id', $hermesId)
            ->whereNotNull('perfil')
            ->pluck('perfil')
            ->sort()
            ->values()
            ->toArray();

        $this->assertSame(self::EXPECTED_PERFILES, $perfiles);
    }

    // ── 2.4 — pre-existing app_entidad rows for OTHER apps stay untouched ─

    #[Test]
    public function pre_existing_app_entidad_rows_for_other_apps_are_untouched(): void
    {
        // Create a CRM app and an unrelated entidad; attach via app_entidad
        // with perfil=NULL. This is the pre-existing pivot that the Hermes
        // seeder MUST NOT touch (REQ-HPBN-004).
        $crm = App::create([
            'slug' => 'crm',
            'nombre' => 'CRM',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);

        $unrelatedEntidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'identificacion' => 'TEST-OTHER-'.uniqid(),
            'nombre' => 'Pre-existing unrelated entidad',
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Pre-existing pivot row: crm → unrelated entidad with perfil=NULL.
        DB::table('app_entidad')->insert([
            'app_id' => $crm->id,
            'entidad_id' => $unrelatedEntidadId,
            'estado' => 'Activo',
            'fecha_contrato' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runHermesSeeder();

        // The pre-existing CRM pivot row stays intact.
        $crmRow = DB::table('app_entidad')
            ->where('app_id', $crm->id)
            ->where('entidad_id', $unrelatedEntidadId)
            ->first();

        $this->assertNotNull($crmRow, 'Pre-existing CRM pivot row must remain after Hermes seed');
        $this->assertNull(
            $crmRow->perfil,
            'Pre-existing app_entidad row MUST keep perfil=NULL after Hermes seed (REQ-HPBN-004)'
        );
        $this->assertSame('Activo', $crmRow->estado);
        $this->assertSame($unrelatedEntidadId, (int) $crmRow->entidad_id);

        // And the Hermes app did NOT touch the CRM app_id.
        $this->assertSame(
            0,
            DB::table('app_entidad')
                ->where('app_id', $crm->id)
                ->whereNotNull('perfil')
                ->count(),
            'No Hermes perfil rows must leak into the CRM app_entidad set'
        );
    }
}
