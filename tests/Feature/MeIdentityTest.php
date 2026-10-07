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

    #[Test]
    public function http_endpoint_returns_11_canonical_apps_for_admin(): void
    {
        // Clear caches so we get a fresh compute.
        Cache::flush();

        // Resolve the admin by email (more robust than hardcoded id).
        $admin = Usuario::where('email', 'admin@tecnoinnsoft.dev')
            ->where('rol_id', 1)
            ->first();

        $this->assertNotNull($admin, 'Admin user must exist after seed');

        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me/identity');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $apps = $response->json('data.apps');
        $this->assertIsArray($apps, 'data.apps must be an array');
        $this->assertCount(11, $apps, 'HTTP endpoint must return 11 canonical apps');

        $actualSlugs = collect($apps)->pluck('slug')->sort()->values()->toArray();

        $expectedSlugs = collect([
            'concordia', 'fama', 'janus', 'mercurio', 'minerva',
            'numeria', 'safe-health', 'tempus', 'tis', 'vesta', 'vigil',
        ])->sort()->values()->toArray();

        $this->assertSame($expectedSlugs, $actualSlugs, 'HTTP endpoint must return the 11 canonical slugs');
    }

    #[Test]
    public function t12_e7_user_updated_event_is_dispatched_to_outbox_post_seed(): void
    {
        // Verify T1.2 (janus-apps-canonicalization): after seed, a
        // `user.updated` event for the admin is enqueued in webhook_outbox
        // so mercurio_users_snapshot populates immediately (no need to
        // wait for the 60s periodic loop).

        $admin = Usuario::where('email', 'admin@tecnoinnsoft.dev')
            ->where('rol_id', 1)
            ->first();

        $this->assertNotNull($admin, 'Admin user must exist after seed');

        // The outbox table must exist (T4.1 migration ran).
        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('webhook_outbox'),
            'webhook_outbox table must exist (T4.1 migration applied)'
        );

        // At least one user.updated event for the admin must be pending.
        $rows = DB::table('webhook_outbox')
            ->where('event_type', 'user.updated')
            ->where('payload_json', 'like', '%"user_id":'.((int) $admin->id).'%')
            ->where('status', 'pending')
            ->get();

        $this->assertGreaterThanOrEqual(1, $rows->count(), 'T1.2: at least one user.updated event must be enqueued for the admin');

        // Payload must include the 11 canonical slugs and wildcard permission.
        $payload = json_decode($rows->first()->payload_json, true);
        $this->assertArrayHasKey('apps', $payload, 'E7 payload must include apps array');
        $this->assertArrayHasKey('permissions', $payload, 'E7 payload must include permissions array');
        $this->assertCount(11, $payload['apps'], 'E7 payload must include 11 apps');
        $this->assertSame(['*'], $payload['permissions'], 'E7 payload must include wildcard permission');
        $this->assertSame($admin->id, $payload['user_id'], 'E7 payload user_id must match the admin');
        $this->assertSame('admin@tecnoinnsoft.dev', $payload['email'], 'E7 payload email must match');
    }
}
