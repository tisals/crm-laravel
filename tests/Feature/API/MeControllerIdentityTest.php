<?php

namespace Tests\Feature\API;

use App\Models\App;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioAppPermiso;
use App\Models\UserIdentitySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the /me/identity endpoint added in
 * multi-app-auth-identity Sprint 1.
 *
 * Covers:
 *   - Returns bundle with user + apps + permisos + scope_label
 *   - Cache hit second call (no DB re-read)
 *   - Stale snapshot triggers recompute (is_stale=1 → fresh payload)
 *   - scope_label is always 'v1' (forward-compat contract)
 */
class MeControllerIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateWithApps(): array
    {
        // Create a Comercial rol with one core permission.
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.index']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.show']);

        $user = Usuario::create([
            'nombre' => 'Comercial User',
            'email' => 'comercial.identity@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        // Create the transitive link: user -> entidad -> app
        $entidad = Entidad::create(['nombre' => 'Test Entidad', 'estado' => 'Activo']);
        DB::table('entidad_usuario')->insert([
            'usuario_id' => $user->id,
            'entidad_id' => $entidad->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $brp = App::create([
            'slug' => 'brp',
            'nombre' => 'BRP',
            'tipo' => 'external',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);
        DB::table('app_entidad')->insert([
            'app_id' => $brp->id,
            'entidad_id' => $entidad->id,
            'estado' => 'Activo',
            'fecha_contrato' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UsuarioAppPermiso::create([
            'usuario_id' => $user->id,
            'app_id' => $brp->id,
            'vista' => 'brp.admin',
        ]);

        return [
            'token' => $user->createToken('test-token')->plainTextToken,
            'user' => $user,
            'rol' => $rol,
            'app' => $brp,
            'entidad' => $entidad,
        ];
    }

    #[Test]
    public function it_returns_identity_bundle_with_apps_and_permisos(): void
    {
        ['token' => $token, 'user' => $user, 'app' => $brp] = $this->authenticateWithApps();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/identity');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.rol.nombre', 'Comercial')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'nombre', 'email', 'rol_id'],
                    'rol' => ['id', 'nombre'],
                    'apps' => [
                        '*' => ['id', 'slug', 'nombre', 'permisos'],
                    ],
                    'permisos',
                    'scope_label',
                    'snapshot_at',
                ],
            ]);

        // Bundle contains the scoped vista for the user's app
        $this->assertContains('brp.admin', $response->json('data.permisos'));
        // Bundle contains the core vistas
        $this->assertContains('contacto.index', $response->json('data.permisos'));
        $this->assertContains('contacto.show', $response->json('data.permisos'));
    }

    #[Test]
    public function it_returns_scope_label_v1(): void
    {
        ['token' => $token] = $this->authenticateWithApps();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/identity');

        $response->assertStatus(200)
            ->assertJsonPath('data.scope_label', 'v1');

        // /me/permisos also returns scope_label
        $response2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/permisos');

        $response2->assertStatus(200)
            ->assertJsonPath('data.scope_label', 'v1');
    }

    #[Test]
    public function it_returns_cached_payload_on_second_call(): void
    {
        ['token' => $token, 'user' => $user] = $this->authenticateWithApps();

        // First call populates the snapshot + cache.
        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/identity');
        $first->assertStatus(200);

        // Snapshot row exists.
        $this->assertDatabaseHas('user_identity_snapshot', [
            'user_id' => $user->id,
            'scope_label' => 'v1',
            'is_stale' => 0,
        ]);

        // Delete the snapshot row but DON'T bust the cache. The next
        // read should still succeed because it serves from the Redis
        // cache layer (L1).
        UserIdentitySnapshot::where('user_id', $user->id)->delete();

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/identity');

        $second->assertStatus(200)
            ->assertJsonPath('data.user.id', $user->id);

        // The snapshot is recreated by the recompute path ONLY when the
        // cache missed. If the cache returned the payload, the snapshot
        // is still absent — which proves the cache served the call.
        $this->assertDatabaseMissing('user_identity_snapshot', [
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function it_recomputes_when_snapshot_is_marked_stale(): void
    {
        ['token' => $token, 'user' => $user, 'app' => $brp] = $this->authenticateWithApps();

        // Force the cache to be cold so the next read must consult the
        // snapshot table.
        Cache::flush();

        // First read computes & persists the snapshot.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/identity')
            ->assertStatus(200);

        // Mark the snapshot stale (simulating an admin permission
        // mutation that triggered cache-bust).
        UserIdentitySnapshot::where('user_id', $user->id)
            ->update(['is_stale' => 1]);

        // Drop the cache too so the read path is forced all the way
        // through to the recompute branch.
        Cache::flush();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/identity');

        $response->assertStatus(200)
            ->assertJsonPath('data.user.id', $user->id);

        // After the call, the snapshot is no longer stale.
        $row = UserIdentitySnapshot::where('user_id', $user->id)->first();
        $this->assertNotNull($row, 'Snapshot row must exist after recompute');
        $this->assertFalse((bool) $row->is_stale, 'Snapshot must be marked fresh after recompute');
    }

    #[Test]
    public function me_permisos_returns_flat_deduped_list(): void
    {
        ['token' => $token] = $this->authenticateWithApps();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/permisos');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.scope_label', 'v1')
            ->assertJsonStructure([
                'data' => [
                    'permisos',
                    'scope_label',
                    'total',
                ],
            ]);

        $permisos = $response->json('data.permisos');
        $this->assertIsArray($permisos);
        $this->assertContains('contacto.index', $permisos);
        $this->assertContains('brp.admin', $permisos);

        // Deduped — assert no duplicate entries
        $this->assertSame(count($permisos), count(array_unique($permisos)));
    }

    #[Test]
    public function unauthenticated_user_gets_401(): void
    {
        $response = $this->getJson('/api/v1/me/identity');
        $response->assertStatus(401);

        $response2 = $this->getJson('/api/v1/me/permisos');
        $response2->assertStatus(401);
    }
}
