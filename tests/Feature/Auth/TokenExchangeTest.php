<?php

namespace Tests\Feature\API\Auth;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TokenExchangeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exchanges_credentials_for_token(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Usuario::create([
            'nombre' => 'Mercurio Test',
            'email' => 'mercurio@tecnoinnsoft.dev',
            'password_hash' => bcrypt('correct-horse-battery-staple'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $response = $this->postJson('/api/v1/auth/token-exchange', [
            'email' => 'mercurio@tecnoinnsoft.dev',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'access_token',
                    'usuario' => ['id', 'email', 'nombres'],
                    'user' => ['id', 'email', 'nombres'],
                    'apps',
                    'entidades',
                    'expires_at',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.usuario.email', 'mercurio@tecnoinnsoft.dev');
    }

    #[Test]
    public function it_rejects_invalid_password(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Usuario::create([
            'nombre' => 'Mercurio Test',
            'email' => 'mercurio@tecnoinnsoft.dev',
            'password_hash' => bcrypt('correct-horse-battery-staple'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        $response = $this->postJson('/api/v1/auth/token-exchange', [
            'email' => 'mercurio@tecnoinnsoft.dev',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'invalid_credentials');
    }

    #[Test]
    public function it_rejects_unknown_email(): void
    {
        $response = $this->postJson('/api/v1/auth/token-exchange', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'invalid_credentials');
    }

    #[Test]
    public function it_rejects_inactive_user(): void
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Usuario::create([
            'nombre' => 'Inactive',
            'email' => 'inactive@example.com',
            'password_hash' => bcrypt('correct-horse-battery-staple'),
            'rol_id' => $rol->id,
            'estado' => 'Inactivo',
        ]);

        $response = $this->postJson('/api/v1/auth/token-exchange', [
            'email' => 'inactive@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'invalid_credentials');
    }

    #[Test]
    public function it_validates_missing_email(): void
    {
        $response = $this->postJson('/api/v1/auth/token-exchange', [
            'password' => 'whatever',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_validates_missing_password(): void
    {
        $response = $this->postJson('/api/v1/auth/token-exchange', [
            'email' => 'someone@example.com',
        ]);

        $response->assertStatus(422);
    }
}