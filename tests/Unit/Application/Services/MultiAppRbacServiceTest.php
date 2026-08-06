<?php

namespace Tests\Unit\Application\Services;

use App\Application\Services\MultiAppRbacService;
use App\Models\App;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioAppPermiso;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for MultiAppRbacService.
 *
 * Covers the six scenarios required by the spec:
 *   1. SuperAdmin bypass via permisos.vista='*'
 *   2. Usuario with only rol permissions
 *   3. Usuario with app-scoped override
 *   4. Cross-app isolation (perm on app A, check on app B → false)
 *   5. Cache hit / miss behavior
 *   6. Redis-down fallback to direct DB
 */
class MultiAppRbacServiceTest extends TestCase
{
    use RefreshDatabase;

    private MultiAppRbacService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MultiAppRbacService(Cache::store());
    }

    #[Test]
    public function it_grants_access_for_superadmin_via_wildcard(): void
    {
        // Setup: SuperAdmin rol with vista='*'
        $rol = Rol::create(['nombre' => 'SuperAdmin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $user = Usuario::create([
            'nombre' => 'Admin',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        // Any app, any vista → true
        $this->assertTrue($this->service->hasPermission($user->id, 1, 'contacto.update'));
        $this->assertTrue($this->service->hasPermission($user->id, 2, 'apps.destroy'));
        $this->assertTrue($this->service->hasPermission($user->id, null, 'whatever.vista'));
    }

    #[Test]
    public function it_grants_access_via_rol_permissions(): void
    {
        // Setup: Comercial rol with `contacto.index` only
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.index']);

        $user = Usuario::create([
            'nombre' => 'Comercial',
            'email' => 'comercial@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $this->assertTrue($this->service->hasPermission($user->id, null, 'contacto.index'));
        $this->assertFalse($this->service->hasPermission($user->id, null, 'contacto.update'));
    }

    #[Test]
    public function it_grants_access_via_app_scoped_override(): void
    {
        // Setup: Comercial without `contacto.update` in core, but with
        // a scoped override for app "brp"
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.index']);
        // NOTE: no `contacto.update` in core permisos

        $user = Usuario::create([
            'nombre' => 'Comercial',
            'email' => 'comercial@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $brp = App::create([
            'slug' => 'brp',
            'nombre' => 'BRP',
            'tipo' => 'external',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);

        UsuarioAppPermiso::create([
            'usuario_id' => $user->id,
            'app_id' => $brp->id,
            'vista' => 'contacto.update',
        ]);

        // User has the scoped override for brp → allowed
        $this->assertTrue(
            $this->service->hasPermission($user->id, $brp->id, 'contacto.update')
        );

        // Without app context → still denied (no core permission)
        $this->assertFalse(
            $this->service->hasPermission($user->id, null, 'contacto.update')
        );
    }

    #[Test]
    public function it_isolates_permissions_across_apps(): void
    {
        // Setup: user has scoped override on app "brp" but NOT on "indicadores"
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        // Even adding `contacto.update` to the rol would still allow the
        // test to pass — but we want to specifically test the app-scoping
        // path, so we leave it out and only grant it via scoped override.

        $user = Usuario::create([
            'nombre' => 'Comercial',
            'email' => 'comercial@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $brp = App::create([
            'slug' => 'brp',
            'nombre' => 'BRP',
            'tipo' => 'external',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);
        $ind = App::create([
            'slug' => 'indicadores',
            'nombre' => 'Indicadores',
            'tipo' => 'external',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);

        UsuarioAppPermiso::create([
            'usuario_id' => $user->id,
            'app_id' => $brp->id,
            'vista' => 'contacto.update',
        ]);

        // Cross-app check: brp → allowed
        $this->assertTrue(
            $this->service->hasPermission($user->id, $brp->id, 'contacto.update'),
            'Should allow scoped grant on brp'
        );

        // Cross-app check: indicadores → denied (no leak)
        $this->assertFalse(
            $this->service->hasPermission($user->id, $ind->id, 'contacto.update'),
            'Must NOT leak brp grant into indicadores'
        );
    }

    #[Test]
    public function it_caches_results_in_redis(): void
    {
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.index']);

        $user = Usuario::create([
            'nombre' => 'Comercial',
            'email' => 'comercial@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        // First call → cache miss → DB hit
        $this->assertTrue($this->service->hasPermission($user->id, null, 'contacto.index'));

        // In the test env CACHE_STORE=array, so we can't peek into the
        // underlying Redis client. Instead, prove the cache is hot by
        // mutating the underlying row and observing the second call
        // returns the (now-stale) cached value.
        Permiso::where('rol_id', $rol->id)->delete();

        $this->assertTrue(
            $this->service->hasPermission($user->id, null, 'contacto.index'),
            'Cached result should still return true after the underlying row is gone'
        );

        // After invalidate the cache must be cold, so the next call
        // re-checks the DB and now returns false (the row is gone).
        $this->service->invalidate($user->id, null, 'contacto.index');
        $this->assertFalse(
            $this->service->hasPermission($user->id, null, 'contacto.index'),
            'After invalidate, the service must see the DB change'
        );
    }

    #[Test]
    public function it_falls_back_to_db_when_cache_throws(): void
    {
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.index']);

        $user = Usuario::create([
            'nombre' => 'Comercial',
            'email' => 'comercial@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        // Inject a broken cache that throws on every operation. We use
        // Mockery because CacheContract extends the PSR CacheInterface
        // which has dozens of methods with strict type signatures — too
        // brittle to hand-roll in an anonymous class.
        $brokenCache = Mockery::mock(CacheContract::class);
        $brokenCache->shouldReceive('remember')->andThrow(new \RuntimeException('Redis down'));
        $brokenCache->shouldReceive('get')->andThrow(new \RuntimeException('Redis down'));
        $brokenCache->shouldReceive('put')->andThrow(new \RuntimeException('Redis down'));
        $brokenCache->shouldReceive('forget')->andThrow(new \RuntimeException('Redis down'));

        $service = new MultiAppRbacService($brokenCache);

        // Must fall back to DB and still return the right answer.
        $this->assertTrue($service->hasPermission($user->id, null, 'contacto.index'));
        $this->assertFalse($service->hasPermission($user->id, null, 'contacto.update'));
    }

    #[Test]
    public function invalidate_busts_cache_for_specific_user_app_vista(): void
    {
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.index']);

        $user = Usuario::create([
            'nombre' => 'Comercial',
            'email' => 'comercial@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $this->assertTrue($this->service->hasPermission($user->id, null, 'contacto.index'));

        // Remove the permission and invalidate — the next call must
        // reflect the change.
        Permiso::where('rol_id', $rol->id)->delete();
        $this->service->invalidate($user->id, null, 'contacto.index');

        $this->assertFalse(
            $this->service->hasPermission($user->id, null, 'contacto.index'),
            'After invalidate, the service must re-check the DB'
        );
    }

    #[Test]
    public function invalidate_all_for_user_clears_every_cached_entry(): void
    {
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.index']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => 'contacto.show']);

        $user = Usuario::create([
            'nombre' => 'Comercial',
            'email' => 'comercial@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        // Warm the cache with two different vistas
        $this->service->hasPermission($user->id, null, 'contacto.index');
        $this->service->hasPermission($user->id, null, 'contacto.show');

        // Drop the underlying permissions
        Permiso::where('rol_id', $rol->id)->delete();

        // Bump version → both cached entries now miss
        $this->service->invalidateAllForUser($user->id);

        $this->assertFalse($this->service->hasPermission($user->id, null, 'contacto.index'));
        $this->assertFalse($this->service->hasPermission($user->id, null, 'contacto.show'));
    }

    /**
     * Helper kept for future Redis-specific assertions (not used in the
     * current test suite since CACHE_STORE=array in phpunit.xml makes
     * the cache opaque to introspection).
     */
    private function allCacheKeys(CacheContract $store): array
    {
        try {
            $inner = $store->getStore();
            if (method_exists($inner, 'connection')) {
                $redis = $inner->connection();
                if (method_exists($redis, 'keys')) {
                    $keys = $redis->keys('*');

                    return is_array($keys) ? $keys : [];
                }
            }
        } catch (\Throwable $e) {
            // ignore — best-effort
        }

        return [];
    }
}
