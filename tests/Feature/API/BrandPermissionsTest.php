<?php

namespace Tests\Unit\API;

use App\Models\Usuario;
use App\Models\Entidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrandPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    #[Test]
    public function it_returns_brand_permissions_for_admin_user()
    {
        $user = Usuario::firstOrCreate(
            ['email' => 'admin@tecnoinnsoft.dev'],
            [
                'nombre' => 'Admin',
                'password_hash' => bcrypt('password'),
                'rol_id' => 1,
                'estado' => 'Activo',
            ]
        );

        $ent1 = Entidad::firstOrCreate(
            ['identificacion' => '900000001-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Tecnoinnsoft',
                'dominio' => 'tecnoinnsoft.com',
                'estado' => 'Propia',
            ]
        );

        $ent2 = Entidad::firstOrCreate(
            ['identificacion' => '900000002-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Deseguridad.dev',
                'dominio' => 'deseguridad.net',
                'estado' => 'Propia',
            ]
        );

        $user->entidades()->attach([$ent1->id, $ent2->id]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/users/' . $user->id . '/brands');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_id' => (string) $user->id,
                    'brand_permissions' => ['tecnoinnsoft.com', 'deseguridad.net'],
                ],
            ]);
    }

    #[Test]
    public function it_returns_404_for_non_existent_user()
    {
        $user = Usuario::firstOrCreate(
            ['email' => 'temp_brands_test@test.com'],
            [
                'nombre' => 'Temp',
                'password_hash' => bcrypt('password'),
                'rol_id' => 1,
                'estado' => 'Activo',
            ]
        );
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/users/99999/brands');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => 'USER_NOT_FOUND',
                'detail' => 'User 99999 does not exist',
            ]);
    }

    #[Test]
    public function it_requires_authentication()
    {
        $response = $this->getJson('/api/v1/users/1/brands');
        $response->assertStatus(401);
    }
}
