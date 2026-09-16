<?php

namespace Tests\Feature\Auth;

use App\Application\UseCases\ValidateApiKeyUseCase;
use App\Models\App;
use App\Models\Entidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for `ValidateApiKeyUseCase` — the m2m auth endpoint used by
 * BRP, SAIlus, the WP-plugin, and every other external client that
 * authenticates with `X-API-Key: <dominio>`.
 *
 * The use case resolves an `api_key` (a `presencia_online.url`
 * row, typed `tipo='web'`) to its `entidad`, then composes the
 * payload returned to the caller. Pre-rewrite this always emitted
 * `permissions: []`; the fix populates it from `app_entidad` joined
 * to `apps`, restricted to `estado='Activo'`.
 *
 * Per `Docs/openapi/auth.yaml`:
 *   `permissions` is a flat array of `apps.slug` strings, sorted
 *   alphabetically (deterministic byte-stable payload across DB
 *   engines). Empty `[]` when the entidad has no active app
 *   contracts; never `null`. Invalid api key → `null` from the use
 *   case (the controller maps that to a 401 response).
 */
class ValidateApiKeyPermissionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_active_app_slugs_as_permissions_for_valid_api_key(): void
    {
        $entidad = $this->seedEntidadWithApiKey('ent-with-apps.test');

        // Two apps with `estado='Activo'` and one with `estado='Suspendido'`.
        // Only the Activos should show up in permissions.
        $mercurio = $this->seedApp('mercurio');
        $sailus = $this->seedApp('sailus');
        $vesta = $this->seedApp('vesta');

        $this->seedAppEntidad($entidad->id, $mercurio->id, 'Activo');
        $this->seedAppEntidad($entidad->id, $sailus->id, 'Activo');
        $this->seedAppEntidad($entidad->id, $vesta->id, 'Suspendido');

        $response = $this->getJson('/api/v1/auth/validate-key', [
            'X-API-Key' => 'ent-with-apps.test',
        ]);

        $response->assertStatus(200)
            ->assertExactJson([
                'valid' => true,
                'bot_id' => "bot_{$entidad->id}",
                'name' => $entidad->nombre,
                // Sorted alphabetically for deterministic cached payload.
                'permissions' => ['mercurio', 'sailus'],
            ]);
    }

    #[Test]
    public function it_returns_empty_permissions_array_when_entidad_has_no_active_apps(): void
    {
        $entidad = $this->seedEntidadWithApiKey('ent-no-apps.test');

        // Apps exist but the entidad has no `app_entidad` rows.
        $this->seedApp('mercurio');
        $this->seedApp('sailus');

        $response = $this->getJson('/api/v1/auth/validate-key', [
            'X-API-Key' => 'ent-no-apps.test',
        ]);

        $response->assertStatus(200)
            ->assertExactJson([
                'valid' => true,
                'bot_id' => "bot_{$entidad->id}",
                'name' => $entidad->nombre,
                // Empty array, NOT null. Spec contract.
                'permissions' => [],
            ]);
    }

    #[Test]
    public function use_case_returns_null_for_invalid_api_key(): void
    {
        // Direct use-case assertion — the spec contract is that an
        // unknown api_key yields `null`. The HTTP layer maps this to
        // a 401 response (covered by the next test).
        $useCase = app(ValidateApiKeyUseCase::class);
        $result = $useCase->execute('does-not-exist.test');

        $this->assertNull($result);
    }

    #[Test]
    public function http_endpoint_returns_401_for_invalid_api_key(): void
    {
        $response = $this->getJson('/api/v1/auth/validate-key', [
            'X-API-Key' => 'does-not-exist.test',
        ]);

        $response->assertStatus(401)
            ->assertExactJson([
                'valid' => false,
                'error' => 'API key inválida',
            ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Create an `Entidad`, attach the api-key as a `presencia_online`
     * row (`tipo='web'` / `plataforma='otro'`), and stamp an open
     * `entidad_relacion` pivot so the use case resolves the api key.
     *
     * Mirrors the wiring in `BrandPermissionsTest::setUp` and in
     * `ValidateApiKeyMiddleware::handle`: the api-key lookup joins
     * `presencia_online` ↔ `entidad_relacion` (effective_to IS NULL).
     */
    private function seedEntidadWithApiKey(string $apiKey): Entidad
    {
        $now = now();

        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900'.random_int(100000, 999999).'-'.random_int(0, 9),
            'nombre' => 'Test Entidad '.uniqid(),
        ]);

        DB::table('presencia_online')->insert([
            'entidad_id' => $entidad->id,
            'tipo' => 'web',
            'plataforma' => 'otro',
            'url' => $apiKey,
            'es_principal' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('entidad_relacion')->insert([
            'entidad_id' => $entidad->id,
            'tipo_relacion' => 'propia',
            'effective_from' => $now->toDateString(),
            'effective_to' => null,
            'frecuencia' => 'unica',
            'recurrencia_cada_meses' => null,
            'vigencia_meses' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $entidad;
    }

    private function seedApp(string $slug): App
    {
        return App::create([
            'slug' => $slug,
            'nombre' => ucfirst($slug),
            'tipo' => 'internal',
            'auth_type' => 'sanctum',
            'activo' => true,
        ]);
    }

    private function seedAppEntidad(int $entidadId, int $appId, string $estado): void
    {
        $now = now();
        DB::table('app_entidad')->insert([
            'app_id' => $appId,
            'entidad_id' => $entidadId,
            'estado' => $estado,
            'fecha_contrato' => $now->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
