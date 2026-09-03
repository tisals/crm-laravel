<?php

namespace Tests\Feature\Seeders;

use App\Models\App;
use Database\Seeders\SailusAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-D (Phase 2): SailusAgentSeeder (REQ-HPBN-002, REQ-HPBN-003, REQ-HPBN-004).
 * (Renamed from HermesAppSeeder on 2026-08-28 — see `config/sailus.php`
 * header for the brand rename history.)
 *
 * The seeder creates:
 *   - exactly 1 `apps` row with slug='sailus', tipo='internal', auth_type='sanctum', activo=true
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
class SailusAgentSeederTest extends TestCase
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
     * Helper: seed the SAIlus Agent app via the production seeder class.
     */
    private function runSailusSeeder(): void
    {
        $this->artisan('db:seed', ['--class' => SailusAgentSeeder::class])
            ->assertExitCode(0);
    }

    // ── 2.1 — exactly 1 apps row with canonical SAIlus Agent attributes ─

    #[Test]
    public function seeder_creates_exactly_one_sailus_apps_row(): void
    {
        $this->runSailusSeeder();

        $count = DB::table('apps')->where('slug', 'sailus')->count();

        $this->assertSame(
            1,
            $count,
            'Seeder MUST create exactly 1 apps row with slug=sailus'
        );

        $row = DB::table('apps')->where('slug', 'sailus')->first();

        $this->assertSame(
            'internal',
            $row->tipo,
            'sailus apps row MUST have tipo=internal'
        );
        $this->assertSame(
            'sanctum',
            $row->auth_type,
            'sailus apps row MUST have auth_type=sanctum'
        );
        $this->assertTrue(
            (bool) $row->activo,
            'sailus apps row MUST have activo=true'
        );
        $this->assertNotEmpty(
            $row->nombre,
            'sailus apps row MUST have a non-empty nombre'
        );
    }

    // ── 2.2 — exactly 5 app_entidad rows with the 5 canonical perfils ──

    #[Test]
    public function seeder_creates_five_app_entidad_profile_rows(): void
    {
        $this->runSailusSeeder();

        $sailusId = DB::table('apps')->where('slug', 'sailus')->value('id');
        $this->assertNotNull($sailusId, 'SAIlus Agent apps row must exist');

        $perfiles = DB::table('app_entidad')
            ->where('app_id', $sailusId)
            ->whereNotNull('perfil')
            ->pluck('perfil')
            ->sort()
            ->values()
            ->toArray();

        $this->assertSame(
            self::EXPECTED_PERFILES,
            $perfiles,
            'The 5 perfil slugs MUST exactly match the canonical SAIlus Agent profiles'
        );

        // Every row points to a non-null entidad (canonical brand entity) and is active.
        $rows = DB::table('app_entidad')
            ->where('app_id', $sailusId)
            ->whereNotNull('perfil')
            ->get();

        foreach ($rows as $row) {
            $this->assertNotNull(
                $row->entidad_id,
                "SAIlus Agent profile row ({$row->perfil}) MUST link to an entidad_id"
            );
            $this->assertSame(
                'Activo',
                $row->estado,
                "SAIlus Agent profile row ({$row->perfil}) MUST be in estado=Activo"
            );
        }
    }

    // ── 2.3 — seeder is idempotent (running twice keeps counts stable) ─

    #[Test]
    public function seeder_is_idempotent_running_twice_keeps_counts_stable(): void
    {
        $this->runSailusSeeder();
        $this->runSailusSeeder();

        $sailusId = DB::table('apps')->where('slug', 'sailus')->value('id');

        $this->assertSame(
            1,
            DB::table('apps')->where('slug', 'sailus')->count(),
            'Idempotency: 2nd run MUST NOT duplicate the apps row'
        );

        $profileCount = DB::table('app_entidad')
            ->where('app_id', $sailusId)
            ->whereNotNull('perfil')
            ->count();

        $this->assertSame(
            5,
            $profileCount,
            'Idempotency: 2nd run MUST keep exactly 5 profile rows'
        );

        // Perfil set unchanged.
        $perfiles = DB::table('app_entidad')
            ->where('app_id', $sailusId)
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
        // Create a Minerva app and an unrelated entidad; attach via app_entidad
        // with perfil=NULL. This is the pre-existing pivot that the SAIlus Agent
        // seeder MUST NOT touch (REQ-HPBN-004).
        $minerva = App::create([
            'slug' => 'minerva',
            'nombre' => 'Minerva',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);

        $unrelatedEntidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'identificacion' => 'TEST-OTHER-'.uniqid(),
            'nombre' => 'Pre-existing unrelated entidad',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Pre-existing pivot row: minerva → unrelated entidad with perfil=NULL.
        DB::table('app_entidad')->insert([
            'app_id' => $minerva->id,
            'entidad_id' => $unrelatedEntidadId,
            'estado' => 'Activo',
            'fecha_contrato' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runSailusSeeder();

        // The pre-existing Minerva pivot row stays intact.
        $minervaRow = DB::table('app_entidad')
            ->where('app_id', $minerva->id)
            ->where('entidad_id', $unrelatedEntidadId)
            ->first();

        $this->assertNotNull($minervaRow, 'Pre-existing Minerva pivot row must remain after SAIlus Agent seed');
        $this->assertNull(
            $minervaRow->perfil,
            'Pre-existing app_entidad row MUST keep perfil=NULL after SAIlus Agent seed (REQ-HPBN-004)'
        );
        $this->assertSame('Activo', $minervaRow->estado);
        $this->assertSame($unrelatedEntidadId, (int) $minervaRow->entidad_id);

        // And the SAIlus Agent app did NOT touch the Minerva app_id.
        $this->assertSame(
            0,
            DB::table('app_entidad')
                ->where('app_id', $minerva->id)
                ->whereNotNull('perfil')
                ->count(),
            'No SAIlus Agent perfil rows must leak into the Minerva app_entidad set'
        );
    }
}
