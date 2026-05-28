<?php
/**
 * License Integration Tests (Strict TDD Mode)
 */

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Contacto;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LicenseIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        // Create a user and Sanctum token for authentication
        $user = Usuario::create([
            'nombre' => 'SAIlus Agent',
            'email' => 'agent@sailus.dev',
            'password_hash' => bcrypt('password'),
            'rol_id' => 1,
            'estado' => 'Activo',
        ]);
        $this->token = $user->createToken('sailus')->plainTextToken;

        // Seed some products
        Producto::create(['nombre' => 'Starter Plan', 'tipo' => 'suscripcion', 'precio' => 10, 'estado' => 'Activo']);
        Producto::create(['nombre' => 'Plan Pro', 'tipo' => 'suscripcion', 'precio' => 97, 'estado' => 'Activo']);
    }

    #[Test]
    public function webhook_purchase_creates_resources_generates_token_and_sends_email()
    {
        Mail::fake();

        $payload = [
            'source' => 'fluent_cart',
            'order_id' => 'fc_order_12345',
            'customer_email' => 'user@empresa.com',
            'customer_name' => 'Juan Pérez',
            'plan_id' => 'pro',
            'amount' => 97.00,
            'currency' => 'COP',
            'subscription_id' => 'fc_sub_67890',
            'billing_interval' => 'monthly',
            'site_url' => 'https://deseguridad.net'
        ];

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/webhook/purchase', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'email_sent' => true,
            ])
            ->assertJsonStructure([
                'org_id',
                'contact_id',
                'service_id',
                'activation_token',
            ]);

        $this->assertDatabaseHas('entidad', [
            'nombre' => 'Juan Pérez Org',
        ]);

        $this->assertDatabaseHas('contacto', [
            'email_contacto' => 'user@empresa.com',
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
        ]);

        $this->assertDatabaseHas('servicios', [
            'plan_id' => 'pro',
            'subscription_id' => 'fc_sub_67890',
            'tier' => 'premium', // 'pro' maps to premium
        ]);

        $servicio = Servicio::where('subscription_id', 'fc_sub_67890')->first();
        $this->assertNotNull($servicio->activation_token);
        $this->assertStringStartsWith('SAILUS-', $servicio->activation_token);

        // Check dates are set properly
        $this->assertEquals(now()->toDateString(), $servicio->fecha_inicio->toDateString());
        $this->assertEquals(now()->addMonth()->toDateString(), $servicio->fecha_fin->toDateString());
    }

    #[Test]
    public function webhook_purchase_reuses_contact_and_creates_new_service_if_exists()
    {
        Mail::fake();

        // 1. Pre-create entity and contact
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => 'NIT-123456',
            'nombre' => 'Empresa Existente',
            'estado' => 'Activo',
        ]);

        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Juan',
            'apellidos' => 'Pérez',
            'email_contacto' => 'user@empresa.com',
            'rol' => 'Contacto WP',
            'estado' => 'Activo',
        ]);

        $payload = [
            'source' => 'fluent_cart',
            'order_id' => 'fc_order_55555',
            'customer_email' => 'user@empresa.com',
            'customer_name' => 'Juan Pérez',
            'plan_id' => 'starter',
            'amount' => 10.00,
            'currency' => 'COP',
            'subscription_id' => 'fc_sub_11111',
            'billing_interval' => 'yearly',
            'site_url' => 'https://deseguridad.net'
        ];

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/webhook/purchase', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'org_id' => $entidad->id,
                'contact_id' => $contacto->id,
            ]);

        $this->assertDatabaseHas('servicios', [
            'entidad_id' => $entidad->id,
            'plan_id' => 'starter',
            'subscription_id' => 'fc_sub_11111',
            'tier' => 'base', // starter maps to base
        ]);

        $servicio = Servicio::where('subscription_id', 'fc_sub_11111')->first();
        // Yearly subscription should set end date to +1 year
        $this->assertEquals(now()->addYear()->toDateString(), $servicio->fecha_fin->toDateString());
    }

    #[Test]
    public function license_validate_returns_valid_license_details()
    {
        // GIVEN a service with an activation token
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => 'NIT-123',
            'nombre' => 'Test Org',
            'estado' => 'Activo',
        ]);

        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Jane',
            'apellidos' => 'Doe',
            'email_contacto' => 'jane@doe.com',
            'rol' => 'Contacto WP',
            'estado' => 'Activo',
        ]);

        $servicio = Servicio::create([
            'entidad_id' => $entidad->id,
            'nombre' => 'Plan Pro',
            'activation_token' => 'SAILUS-VALID-TOKEN-123',
            'plan_id' => 'pro',
            'tier' => 'premium',
            'fecha_inicio' => now(),
            'fecha_fin' => now()->addMonths(6),
            'estado' => 'Activo',
        ]);

        // WHEN we validate with valid token and username
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/license/validate', [
                'username' => 'jane@doe.com',
                'token' => 'SAILUS-VALID-TOKEN-123',
                'site_url' => 'https://deseguridad.net',
                'plugin_version' => '1.0.0'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'active',
                'plan' => 'pro',
                'tier' => 'premium',
                'org_id' => $entidad->id,
                'contact_id' => $contacto->id,
                'service_id' => $servicio->id,
            ]);
    }

    #[Test]
    public function license_validate_returns_expired_status_if_past_expiry()
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => 'NIT-1234',
            'nombre' => 'Test Org 2',
            'estado' => 'Activo',
        ]);

        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Jane',
            'apellidos' => 'Doe',
            'email_contacto' => 'jane@doe.com',
            'rol' => 'Contacto WP',
            'estado' => 'Activo',
        ]);

        $servicio = Servicio::create([
            'entidad_id' => $entidad->id,
            'nombre' => 'Plan Pro',
            'activation_token' => 'SAILUS-EXPIRED-TOKEN',
            'plan_id' => 'pro',
            'tier' => 'premium',
            'fecha_inicio' => now()->subMonths(2),
            'fecha_fin' => now()->subDay(), // Expired yesterday
            'estado' => 'Activo',
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/license/validate', [
                'username' => 'jane@doe.com',
                'token' => 'SAILUS-EXPIRED-TOKEN',
                'site_url' => 'https://deseguridad.net',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'expired',
            ]);
    }

    #[Test]
    public function license_validate_returns_401_for_invalid_token_or_username()
    {
        // 1. Invalid token
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/license/validate', [
                'username' => 'jane@doe.com',
                'token' => 'SAILUS-NON-EXISTENT',
                'site_url' => 'https://deseguridad.net',
            ]);

        $response->assertStatus(401);

        // 2. Token exists but username does not match
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => 'NIT-12345',
            'nombre' => 'Test Org 3',
            'estado' => 'Activo',
        ]);

        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Jane',
            'apellidos' => 'Doe',
            'email_contacto' => 'jane@doe.com',
            'rol' => 'Contacto WP',
            'estado' => 'Activo',
        ]);

        Servicio::create([
            'entidad_id' => $entidad->id,
            'nombre' => 'Plan Pro',
            'activation_token' => 'SAILUS-MATCH-TOKEN',
            'plan_id' => 'pro',
            'tier' => 'premium',
            'fecha_inicio' => now(),
            'fecha_fin' => now()->addMonth(),
            'estado' => 'Activo',
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/license/validate', [
                'username' => 'wrong-email@doe.com',
                'token' => 'SAILUS-MATCH-TOKEN',
                'site_url' => 'https://deseguridad.net',
            ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function service_renew_updates_expiry_and_saves_metadata()
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => 'NIT-999',
            'nombre' => 'Renew Org',
            'estado' => 'Activo',
        ]);

        $servicio = Servicio::create([
            'entidad_id' => $entidad->id,
            'nombre' => 'Plan Pro',
            'activation_token' => 'SAILUS-RENEW-TOKEN',
            'plan_id' => 'pro',
            'tier' => 'premium',
            'fecha_inicio' => now()->subMonth(),
            'fecha_fin' => now()->addDay(),
            'estado' => 'Activo',
        ]);

        $metadata = [
            'payment_id' => 'wompi_charge_67890',
            'amount' => 97.00,
            'currency' => 'COP',
            'payment_method' => 'wompi_subscription'
        ];

        $newExpiresAt = now()->addMonths(2)->toIso8601String();

        $response = $this->withToken($this->token)
            ->putJson("/api/v1/services/{$servicio->id}/renew", [
                'payment_id' => 'wompi_charge_67890',
                'new_expires_at' => $newExpiresAt,
                'amount' => 97.00,
                'currency' => 'COP',
                'payment_method' => 'wompi_subscription'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'new_expires_at'
            ]);

        $servicio->refresh();
        $this->assertEquals(substr($newExpiresAt, 0, 10), $servicio->fecha_fin->toDateString());
        $this->assertEquals($metadata, $servicio->metadata);
    }
}
