<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Producto;
use App\Models\Contacto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SailusIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '999999999-9',
            'nombre' => 'SAIlus Bot',
            'dominio' => 'sailus_bot_test_key',
            'estado' => 'Activo',
        ]);

        $user = Usuario::create([
            'nombre' => 'Test',
            'email' => 'test@sailus.dev',
            'password_hash' => bcrypt('password'),
            'rol_id' => 1,
            'estado' => 'Activo',
        ]);
        $this->token = $user->createToken('test')->plainTextToken;
        $this->apiKey = 'sailus_bot_test_key';
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
            'estado' => 'Activo',
        ]);

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
