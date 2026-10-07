<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Usuario;
use App\Models\UsuarioAppPermiso;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke test for janus-apps-canonicalization.
 *
 * Verifies that after the seed, the identity use case returns exactly 11
 * canonical apps for the admin user.
 */
class MeIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function identity_bundle_contains_11_canonical_apps_for_admin(): void
    {
        // Clear caches so we get a fresh compute.
        Cache::flush();

        $admin = Usuario::where('email', 'admin@tecnoinnsoft.dev')
            ->where('rol_id', 1)
            ->first();

        $this->assertNotNull($admin, 'Admin user must exist after seed');

        // Verify AC1-AC4 via direct DB queries.
        $this->assertEquals(11, App::whereNull('deleted_at')->count(), 'AC1: 11 active apps');
        $this->assertEquals(
            11,
            UsuarioAppPermiso::where('usuario_id', $admin->id)->whereNull('deleted_at')->count(),
            'AC4: 11 app perms for admin'
        );
        $this->assertEquals(
            22,
            DB::table('app_entidad')->whereNull('deleted_at')->count(),
            'AC3: 22 app_entidad rows'
        );

        // Verify the identity bundle via use case.
        $useCase = app(\App\Application\UseCases\Me\GetMyIdentityUseCase::class);
        $payload = $useCase->execute($admin->id);

        $this->assertNotNull($payload, 'Use case must return a payload');
        $this->assertArrayHasKey('apps', $payload);
        $this->assertCount(11, $payload['apps'], 'Should have 11 apps in identity bundle');

        $actualSlugs = array_column($payload['apps'], 'slug');
        sort($actualSlugs);

        $expectedSlugs = [
            'concordia', 'fama', 'janus', 'mercurio', 'minerva',
            'numeria', 'safe-health', 'tempus', 'tis', 'vesta', 'vigil',
        ];
        sort($expectedSlugs);

        $this->assertSame($expectedSlugs, $actualSlugs, 'All 11 canonical slugs must be present');
    }
}
