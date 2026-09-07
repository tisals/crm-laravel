<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SailusIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    private string $token;

    /**
     * Stamp the pivot rows an `entidad` needs to be considered "active"
     * post-Commit 5.5. The legacy `entidad.estado` column is gone —
     * business state lives on `entidad_relacion` (open row with
     * `effective_to IS NULL`). `ValidateApiKeyUseCase` looks up the
     * entidad by the X-API-Key value via the `presencia_online.url`
     * lookup that replaces the dropped `entidad.dominio` column.
     */
    private function stampActivePivot(int $entidadId, string $dominioKey): void
    {
        $now = now();
        DB::table('entidad_relacion')->insert([
            'entidad_id' => $entidadId,
            'tipo_relacion' => 'cliente',
            'effective_from' => $now->toDateString(),
            'effective_to' => null,
            'frecuencia' => 'unica',
            'recurrencia_cada_meses' => null,
            'vigencia_meses' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Mirror the legacy `dominio` API key onto the canonical
        // `presencia_online` table (Commit 3 contract). Pre-Commit 5.5,
        // `entidad.dominio` held the API key; post-Commit 5.5 that
        // lookup now goes through `presencia_online.url` with
        // `tipo='web'` (legacy `dominio` → web presence) and
        // `plataforma='otro'` (generic web URL, not a known platform).
        DB::table('presencia_online')->insert([
            'entidad_id' => $entidadId,
            'tipo' => 'web',
            'plataforma' => 'otro',
            'url' => $dominioKey,
            'es_principal' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Drop the `DatabaseSeeder` chain — pre-Commit 4/5 seeder
        // scripts reference dropped columns (`entidad.estado`,
        // `entidad.dominio`, `contacto.entidad_id`, etc.) and break the
        // RefreshDatabase transaction (SQLSTATE[HY000]: SAVEPOINT
        // trans2 does not exist on subsequent reads). Mirrors the fix
        // applied to `LicenseIntegrationTest` in Commit 5.6.

        // Create the rol the Usuario FK depends on (previously seeded).
        $rol = Rol::firstOrCreate(['nombre' => 'Admin'], ['estado' => 'Activo']);

        // Mirror the (now removed) "SAIlus Bot" entidad. Pre-Commit 5.5,
        // its API key lived on `entidad.dominio`; the lookup helper
        // (`ValidateApiKeyUseCase`) had to be rewired to read the key
        // from `presencia_online.url`.
        $botEntidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '999999999-9',
            'nombre' => 'SAIlus Bot',
        ]);

        $this->apiKey = 'sailus_bot_test_key';
        $this->stampActivePivot((int) $botEntidad->id, $this->apiKey);

        $user = Usuario::create([
            'nombre' => 'Test',
            'email' => 'test@sailus.dev',
            'password_hash' => bcrypt('password'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);
        $this->token = $user->createToken('test')->plainTextToken;
    }

    #[Test]
    public function validate_key_returns_200_with_valid_key()
    {
        $response = $this->withHeaders(['X-API-Key' => $this->apiKey])
            ->getJson('/api/v1/auth/validate-key');

        $response->assertStatus(200)
            ->assertJson([
                'valid' => true,
                'name' => 'SAIlus Bot',
            ])
            ->assertJsonStructure(['bot_id']);
    }

    #[Test]
    public function validate_key_returns_401_with_invalid_key()
    {
        $response = $this->withHeaders(['X-API-Key' => 'invalid_key'])
            ->getJson('/api/v1/auth/validate-key');

        $response->assertStatus(401)
            ->assertJson(['valid' => false]);
    }

    #[Test]
    public function plans_returns_only_suscripcion_products()
    {
        Producto::create(['nombre' => 'Plan Pro', 'tipo' => 'suscripcion', 'precio' => 50000, 'estado' => 'Activo']);
        Producto::create(['nombre' => 'Curso SST', 'tipo' => 'producto', 'precio' => 25000, 'estado' => 'Activo']);

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/plans');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Plan Pro', $data[0]['name']);
    }

    #[Test]
    public function sailus_entidad_returns_entity()
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '888888888-8',
            'nombre' => 'Test Corp',
        ]);
        $this->stampActivePivot((int) $entidad->id, 'test-corp-test-key');

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/sailus/entidad/{$entidad->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.nombre', 'Test Corp');
    }

    #[Test]
    public function webhook_registration_creates_entity_contact_servicio()
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/webhook/registration', [
                'organization_name' => 'Test Org',
                'contact_name' => 'Juan Pérez',
                'contact_email' => 'juan@test.com',
                'plan_type' => 'pro',
                'service_name' => 'la-llave',
                'source' => 'wordpress',
                'diagnostico_data' => ['eje' => 'tecnologia'],
            ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('org_id'));
        $this->assertNotNull($response->json('contact_id'));

        $this->assertDatabaseHas('entidad', ['nombre' => 'Test Org']);
        $this->assertDatabaseHas('contacto', ['email_contacto' => 'juan@test.com']);
        $this->assertDatabaseHas('servicios', ['nombre' => 'la-llave']);
    }

    #[Test]
    public function webhook_returns_409_for_duplicate_email()
    {
        $this->withToken($this->token)->postJson('/api/v1/webhook/registration', [
            'organization_name' => 'Org 1',
            'contact_name' => 'Juan Pérez',
            'contact_email' => 'duplicate@test.com',
            'service_name' => 'test',
        ]);

        $response = $this->withToken($this->token)->postJson('/api/v1/webhook/registration', [
            'organization_name' => 'Org 2',
            'contact_name' => 'Juan Pérez',
            'contact_email' => 'duplicate@test.com',
            'service_name' => 'test',
        ]);

        $response->assertStatus(409);
    }
}
