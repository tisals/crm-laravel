<?php

namespace Tests\Feature\API;

use App\Models\App;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * S1 — the apps catalog owns WHERE an app lives.
 *
 * `apps.launch_url` declares the SSO launch destination of an app, so the
 * hub can derive its module manifest from this API instead of duplicating
 * the destination in authored files.
 */
class AppLaunchUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every key the catalog item exposes. Adding `launch_url` must not
     * rename or drop any of the existing ones.
     */
    private const ITEM_KEYS = [
        'id',
        'slug',
        'nombre',
        'tipo',
        'auth_type',
        'activo',
        'descripcion',
        'launch_url',
        'created_at',
        'updated_at',
    ];

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $this->auth = ['token' => $usuario->createToken('test-token')->plainTextToken];
    }

    private function withAuth(): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->auth['token']);
    }

    private function createApp(array $overrides = []): App
    {
        return App::create(array_merge([
            'slug' => 'documentacion-sst',
            'nombre' => 'Documentacion SST',
            'tipo' => 'external',
            'auth_type' => 'sanctum',
            'activo' => true,
        ], $overrides));
    }

    /**
     * Payload accepted by POST/PUT /api/v1/apps.
     */
    private function appPayload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'documentacion-sst',
            'nombre' => 'Documentacion SST',
            'tipo' => 'external',
            'auth_type' => 'sanctum',
            'activo' => true,
        ], $overrides);
    }

    // ─── Catalog ───────────────────────────────────────────────────────

    #[Test]
    public function it_exposes_launch_url_in_the_apps_catalog(): void
    {
        $this->createApp([
            'launch_url' => 'https://documentacion-sst.tecnoinnsoft.com',
        ]);

        $response = $this->withAuth()->getJson('/api/v1/apps');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data' => ['*' => self::ITEM_KEYS]]])
            ->assertJsonPath('data.data.0.launch_url', 'https://documentacion-sst.tecnoinnsoft.com');
    }

    #[Test]
    public function it_returns_null_launch_url_when_the_app_has_no_destination(): void
    {
        $this->createApp();

        $response = $this->withAuth()->getJson('/api/v1/apps');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['data' => ['*' => self::ITEM_KEYS]]])
            ->assertJsonPath('data.data.0.launch_url', null);
    }

    // ─── Persistence ───────────────────────────────────────────────────

    #[Test]
    public function it_persists_launch_url_on_create(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/apps', $this->appPayload([
            'launch_url' => 'https://documentacion-sst.tecnoinnsoft.com',
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.launch_url', 'https://documentacion-sst.tecnoinnsoft.com');

        $this->assertDatabaseHas('apps', [
            'slug' => 'documentacion-sst',
            'launch_url' => 'https://documentacion-sst.tecnoinnsoft.com',
        ]);
    }

    #[Test]
    public function it_creates_an_app_without_launch_url(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/apps', $this->appPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.launch_url', null);

        $this->assertDatabaseHas('apps', [
            'slug' => 'documentacion-sst',
            'launch_url' => null,
        ]);
    }

    #[Test]
    public function it_persists_launch_url_on_update(): void
    {
        $app = $this->createApp();

        $response = $this->withAuth()->putJson('/api/v1/apps/'.$app->id, $this->appPayload([
            'launch_url' => 'https://documentacion-sst.tecnoinnsoft.com',
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.launch_url', 'https://documentacion-sst.tecnoinnsoft.com');

        $this->assertDatabaseHas('apps', [
            'id' => $app->id,
            'launch_url' => 'https://documentacion-sst.tecnoinnsoft.com',
        ]);
    }

    // ─── Validation ────────────────────────────────────────────────────

    #[Test]
    public function it_rejects_a_relative_launch_url(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/apps', $this->appPayload([
            'launch_url' => '/apps/documentacion-sst',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['launch_url']);

        $this->assertDatabaseMissing('apps', ['slug' => 'documentacion-sst']);
    }

    #[Test]
    public function it_rejects_a_non_http_launch_url(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/apps', $this->appPayload([
            'launch_url' => 'ftp://files.tecnoinnsoft.com/sst',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['launch_url']);

        $this->assertDatabaseMissing('apps', ['slug' => 'documentacion-sst']);
    }

    #[Test]
    public function it_rejects_a_launch_url_longer_than_255_characters(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/apps', $this->appPayload([
            'launch_url' => 'https://tecnoinnsoft.com/'.str_repeat('a', 250),
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['launch_url']);

        $this->assertDatabaseMissing('apps', ['slug' => 'documentacion-sst']);
    }
}
