<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Filter `is_latest` must be configurable:
 *   - Default: API returns only is_latest = true
 *   - With ?is_latest=false: API returns ALL versions
 *
 * This is required by the frontend detail view to show the version history.
 */
class OportunidadIsLatestFilterTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);

        // Grant wildcard permission so the RBAC middleware lets the request through.
        // Without this the request is short-circuited by RbacMiddleware before
        // reaching the IndexOportunidadUseCase.
        Permiso::create([
            'rol_id' => $rol->id,
            'vista' => '*',
        ]);

        $user = Usuario::create([
            'nombre' => 'Test',
            'email' => 'is_latest_test@example.com',
            'password_hash' => bcrypt('password'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);
        $this->token = $user->createToken('test-token')->plainTextToken;

        $entidad = Entidad::create([
            'nombre' => 'Test Corp',
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900999999',
            'estado' => 'Cliente',
        ]);

        // 2 versions: latest (v2) and old (v1)
        $old = Oportunidad::create([
            'codigo' => 'TEST-001-V1',
            'entidad_id' => $entidad->id,
            'fecha' => '2025-01-15',
            'estado' => 'Activa',
            'version' => 1,
            'is_latest' => false,
        ]);
        Oportunidad::create([
            'codigo' => 'TEST-001-V2',
            'entidad_id' => $entidad->id,
            'fecha' => '2025-06-15',
            'estado' => 'Activa',
            'parent_id' => $old->id,
            'version' => 2,
            'is_latest' => true,
        ]);
    }

    #[Test]
    public function default_response_only_includes_latest_versions(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/oportunidades');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data, 'Default debe devolver solo 1 (la versión latest)');
        $this->assertSame('TEST-001-V2', $data[0]['codigo']);
        $this->assertTrue($data[0]['is_latest']);
    }

    #[Test]
    public function with_is_latest_false_includes_all_versions(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/oportunidades?is_latest=false');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(2, $data, 'Con is_latest=false debe devolver las 2 versiones');

        $codigos = collect($data)->pluck('codigo')->sort()->values()->all();
        $this->assertSame(['TEST-001-V1', 'TEST-001-V2'], $codigos);
    }

    #[Test]
    public function with_is_latest_true_explicit_returns_only_latest(): void
    {
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/oportunidades?is_latest=true');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertSame('TEST-001-V2', $data[0]['codigo']);
    }
}
