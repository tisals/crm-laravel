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
        $user = Usuario::create([
            'nombre' => 'Admin',
            'email' => 'admin@tecnoinnsoft.dev',
            'password_hash' => bcrypt('password'),
            'rol_id' => 1,
            'estado' => 'Activo',
        ]);

        $ent1 = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900000001-0',
            'nombre' => 'Tecnoinnsoft',
            'dominio' => 'tecnoinnsoft.com',
            'estado' => 'Propia',
        ]);

        $ent2 = Entidad::create([
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900000002-0',
            'nombre' => 'Deseguridad.dev',
            'dominio' => 'deseguridad.net',
            'estado' => 'Propia',
        ]);

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
        $user = Usuario::create([
            'nombre' => 'Temp',
            'email' => 'temp@test.com',
            'password_hash' => bcrypt('password'),
            'rol_id' => 1,
            'estado' => 'Activo',
        ]);
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
