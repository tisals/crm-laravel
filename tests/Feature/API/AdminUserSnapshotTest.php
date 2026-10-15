<?php

namespace Tests\Feature\API;

use App\Models\Usuario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Smoke test for janus-apps-canonicalization D-D3 follow-up (2026-10-15):
 * GET /api/v1/admin/users/snapshot
 *
 * Verifies the admin endpoint returns the flattened user list that
 * mercurio's SnapshotRefresher consumes.
 */
class AdminUserSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function admin_endpoint_returns_flat_user_list_for_mercurio(): void
    {
        $admin = Usuario::where('email', 'admin@tecnoinnsoft.dev')->where('rol_id', 1)->first();
        $this->assertNotNull($admin, 'Admin user must exist after seed');

        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users/snapshot');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'email', 'name', 'estado', 'rol_id',
                        'apps', 'entities', 'permissions',
                    ],
                ],
            ]);

        $users = $response->json('data');
        $this->assertIsArray($users);
        $this->assertGreaterThanOrEqual(5, count($users), 'Should return at least 5 users (admin + 4 comerciales)');

        // Find the admin in the response
        $adminRow = collect($users)->firstWhere('id', $admin->id);
        $this->assertNotNull($adminRow, 'Admin must be in the snapshot list');
        $this->assertSame('admin@tecnoinnsoft.dev', $adminRow['email']);
        $this->assertSame(1, $adminRow['rol_id']);
        $this->assertSame('Activo', $adminRow['estado']);

        // Admin should have 11 apps (the canonical 11)
        $this->assertCount(11, $adminRow['apps'], 'Admin should have 11 apps');
        $slugs = collect($adminRow['apps'])->pluck('slug')->sort()->values()->toArray();
        $this->assertSame(
            ['concordia', 'fama', 'janus', 'mercurio', 'minerva', 'numeria', 'safe-health', 'tempus', 'tis', 'vesta', 'vigil'],
            $slugs
        );

        // Admin should have 2 entities (Tecnoinnsoft, Desecurity)
        $this->assertCount(2, $adminRow['entities']);

        // Admin should have the wildcard permission
        $this->assertContains('*', $adminRow['permissions']);
    }

    #[Test]
    public function non_admin_user_gets_403(): void
    {
        // The Comercial rol is created by UsuariosTableSeeder
        $comercial = Usuario::where('rol_id', '!=', 1)->first();
        if (! $comercial) {
            $this->markTestSkipped('No non-admin user in seed');
        }

        $token = $comercial->createToken('test-token')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/users/snapshot');

        $response->assertStatus(403);
    }
}