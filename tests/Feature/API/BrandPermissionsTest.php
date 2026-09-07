<?php

namespace Tests\Unit\API;

use App\Models\Entidad;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrandPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The DatabaseSeeder was written pre-Commit 4/5 and references
        // dropped columns (`entidad.estado`, `entidad.dominio`,
        // `entidad.ciudad_cod`, `contacto.entidad_id`). Loading it
        // breaks the RefreshDatabase transaction (SAVEPOINT errors
        // when subsequent reads run). Each test in this file creates
        // the data it needs (Rol, Usuario, Entidad via firstOrCreate).
    }

    #[Test]
    public function it_returns_brand_permissions_for_admin_user()
    {
        $superAdmin = Rol::where('nombre', 'SuperAdmin')->first() ?? Rol::create(['nombre' => 'SuperAdmin', 'estado' => 'Activo']);
        $user = Usuario::firstOrCreate(
            ['email' => 'admin@tecnoinnsoft.dev'],
            [
                'nombre' => 'Admin',
                'password_hash' => bcrypt('password'),
                'rol_id' => $superAdmin->id,
                'estado' => 'Activo',
            ]
        );

        $ent1 = Entidad::firstOrCreate(
            ['identificacion' => '900000001-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Tecnoinnsoft',
            ]
        );

        $ent2 = Entidad::firstOrCreate(
            ['identificacion' => '900000002-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Deseguridad.dev',
            ]
        );

        // Commit 4 / fe99f70 rewired the user → entidad binding through
        // `entidad_persona` keyed on the user's `persona_id`. `Usuario::entidades()`
        // is now a HasManyThrough and no longer exposes BelongsToMany
        // helpers like `syncWithoutDetaching()`. Insert the pivot rows
        // directly to bind the user to both brand entidades.
        foreach ([$ent1->id, $ent2->id] as $entidadId) {
            DB::table('entidad_persona')->insertOrIgnore([
                'persona_id' => $user->persona_id,
                'entidad_id' => $entidadId,
                'categoria' => 'asignacion',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Commit 5.5 dropped `entidad.estado`. The brand filter now
        // matches entidades with an open `propia` pivot row. Insert
        // those pivot rows so the controller picks them up.
        $now = now();
        foreach ([$ent1->id, $ent2->id] as $entidadId) {
            DB::table('entidad_relacion')->insertOrIgnore([
                'entidad_id' => $entidadId,
                'tipo_relacion' => 'propia',
                'effective_from' => $now->toDateString(),
                'effective_to' => null,
                'frecuencia' => 'unica',
                'recurrencia_cada_meses' => null,
                'vigencia_meses' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Commit 4 dropped `entidad.dominio`; the canonical row lives in
        // `presencia_online`. Insert the rows the controller reads.
        foreach ([[$ent1->id, 'tecnoinnsoft.com'], [$ent2->id, 'deseguridad.net']] as [$entidadId, $domain]) {
            DB::table('presencia_online')->insertOrIgnore([
                'entidad_id' => $entidadId,
                'tipo' => 'web',
                'plataforma' => 'otro',
                'url' => $domain,
                'es_principal' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/users/'.$user->id.'/brands');

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
        $superAdmin = Rol::where('nombre', 'SuperAdmin')->first() ?? Rol::create(['nombre' => 'SuperAdmin', 'estado' => 'Activo']);
        $user = Usuario::firstOrCreate(
            ['email' => 'temp_brands_test@test.com'],
            [
                'nombre' => 'Temp',
                'password_hash' => bcrypt('password'),
                'rol_id' => $superAdmin->id,
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
