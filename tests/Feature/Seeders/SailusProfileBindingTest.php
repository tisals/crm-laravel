<?php

namespace Tests\Feature\Seeders;

use App\Models\App;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\SailusAgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-D (Phase 2): SailusProfileBinding (REQ-HPBN-005, REQ-PRBN-006).
 * (Renamed from HermesProfileBinding on 2026-08-28 — see
 * `config/sailus.php` header for the brand rename history.)
 *
 * Two contracts are asserted together in this file because both depend on
 * the same production code path (the `perfil` allow-list on `app_entidad`):
 *
 *   - REQ-HPBN-005 — invalid perfil values (e.g. 'setter-bogus') MUST be
 *     rejected by the validation layer when an admin POSTs to assign an
 *     app to an entidad with `perfil` set. `perfil=null` is accepted.
 *
 *   - REQ-PRBN-006 — a usuario linked via `entidad_usuario` to a SAIlus
 *     Agent-bound entidad MUST see `sailus` in `GET /api/v1/me/apps`. The
 *     `perfil` column is metadata only; it does NOT gate user access.
 *
 * Strict TDD: these are pure feature tests that exercise real HTTP
 * requests and real DB state. No mocks. RED task 2.5 fails before the
 * validation hook + transitive access are wired.
 */
class SailusProfileBindingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper: create the user + entidad + transitive link fixtures that
     * power the /me/apps scenario. Returns the sanctum token.
     *
     * @return array{token: string, user: Usuario, entidad: Entidad, sailus: App}
     */
    private function bindUsuarioToSailusEntidad(): array
    {
        // Seed SAIlus Agent via the production seeder (perfil-bound entidad row is created).
        $this->artisan('db:seed', ['--class' => SailusAgentSeeder::class])
            ->assertExitCode(0);

        // Build a user with a Rol, link to entidad, link entidad to sailus
        // app_entidad (with a perfil). The seeder already linked the sailus
        // app_entidad row to its brand entidad; we need to add an extra
        // entidad_usuario link to that entidad to test transitive access.
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);

        $user = Usuario::create([
            'nombre' => 'SAIlus Agent User',
            'email' => 'sailus.user@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        // Fetch the brand entity that the seeder linked to the setter-safe-health profile.
        $sailusApp = App::where('slug', 'sailus')->first();
        $brandEntidadId = DB::table('app_entidad')
            ->where('app_id', $sailusApp->id)
            ->where('perfil', 'setter-safe-health')
            ->value('entidad_id');

        $entidad = Entidad::findOrFail($brandEntidadId);

        DB::table('entidad_usuario')->insert([
            'usuario_id' => $user->id,
            'entidad_id' => $entidad->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        return [
            'token' => $token,
            'user' => $user,
            'entidad' => $entidad,
            'sailus' => $sailusApp,
        ];
    }

    // ── 2.5a — invalid perfil is rejected by validation layer (REQ-HPBN-005) ──

    #[Test]
    public function invalid_perfil_value_is_rejected_by_validation_layer(): void
    {
        // Build an admin user with Sanctum token + an entidad to assign SAIlus Agent to.
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        // RBAC: the endpoint is gated by `entidad.apps.assign` route permission.
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'entidad.apps.assign']);
        $admin = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Brand',
            'identificacion' => 'TEST-VALIDATION',
            'estado' => 'Activo',
        ]);

        // Seed the SAIlus Agent apps row so the FK exists.
        App::create([
            'slug' => 'sailus',
            'nombre' => 'SAIlus Agent Platform',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);
        $sailusId = App::where('slug', 'sailus')->value('id');

        $token = $admin->createToken('test-token')->plainTextToken;

        // POST with invalid perfil 'setter-bogus' — must be rejected with 422.
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/entidad/{$entidad->id}/apps/{$sailusId}", [
                'perfil' => 'setter-bogus',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['perfil']);

        // No row inserted (FK still points to 0 rows with this invalid perfil).
        $bogusRows = DB::table('app_entidad')
            ->where('app_id', $sailusId)
            ->where('perfil', 'setter-bogus')
            ->count();

        $this->assertSame(0, $bogusRows, 'Invalid perfil MUST NOT be persisted to app_entidad');
    }

    #[Test]
    public function null_perfil_is_accepted(): void
    {
        // Build an admin + entidad to assign SAIlus Agent to.
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'entidad.apps.assign']);
        $admin = Usuario::create([
            'nombre' => 'Admin Null Perfil',
            'email' => 'admin.null@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Brand Null',
            'identificacion' => 'TEST-NULL-PERFIL',
            'estado' => 'Activo',
        ]);

        App::create([
            'slug' => 'sailus',
            'nombre' => 'SAIlus Agent Platform',
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);
        $sailusId = App::where('slug', 'sailus')->value('id');

        $token = $admin->createToken('test-token')->plainTextToken;

        // POST without perfil — must succeed (perfil=null accepted).
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/entidad/{$entidad->id}/apps/{$sailusId}", [
                'estado' => 'Activo',
            ]);

        $response->assertStatus(200);

        $row = DB::table('app_entidad')
            ->where('app_id', $sailusId)
            ->where('entidad_id', $entidad->id)
            ->first();

        $this->assertNotNull($row, 'app_entidad row MUST be inserted when perfil is omitted');
        $this->assertNull($row->perfil, 'perfil MUST be NULL when omitted');
        $this->assertSame('Activo', $row->estado);
    }

    // ── 2.5b — transitive user access returns sailus in /me/apps (REQ-PRBN-006) ──

    #[Test]
    public function usuario_linked_to_sailus_entidad_sees_sailus_in_me_apps(): void
    {
        // Build the full transitive chain:
        //   usuario → entidad_usuario → entidad → app_entidad(app=sailus, perfil=X)
        $ctx = $this->bindUsuarioToSailusEntidad();
        $token = $ctx['token'];

        // Bust the me:apps cache so the next read recomputes.
        Cache::flush();

        // ── Live HTTP test ────────────────────────────────────────────
        // The HTTP layer's `GetMyAppsUseCase` reads via `mysql_read`, which
        // is a SEPARATE PDO instance from the master `mysql` connection.
        // Because the test runs inside a transaction (`RefreshDatabase`),
        // the `mysql_read` connection cannot see uncommitted test data —
        // a pre-existing limitation of the read-replica pattern that PR-D
        // does NOT change.
        //
        // To exercise the live HTTP path on `mysql_read`, we commit the
        // wrapping transaction so both connections see the test fixtures.
        DB::commit();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/apps');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $slugs = collect($response->json('data.apps'))->pluck('slug')->all();

        $this->assertContains(
            'sailus',
            $slugs,
            'GET /api/v1/me/apps MUST include sailus for a usuario linked via entidad_usuario to a SAIlus Agent-bound entidad (REQ-PRBN-006)'
        );

        // perfil is metadata; the me/apps response MUST NOT include it
        // (perfil is for SAIlus Agent runtime, not crm-laravel RBAC).
        foreach ($response->json('data.apps') as $entry) {
            $this->assertArrayNotHasKey(
                'perfil',
                $entry,
                'me/apps response MUST NOT leak the perfil column (it is SAIlus Agent runtime metadata)'
            );
        }

        // Re-open a transaction so `RefreshDatabase` can roll back cleanly
        // when the test finishes (it expects to be inside a transaction).
        DB::beginTransaction();
    }
}
