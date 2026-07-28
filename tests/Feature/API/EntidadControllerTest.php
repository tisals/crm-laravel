<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EntidadControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): string
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return $usuario->createToken('test-token')->plainTextToken;
    }

    #[Test]
    public function it_lists_entidades(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/entidad');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_creates_an_entidad(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/entidad', [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'identificacion' => '900123456',
                'nombre' => 'Tecnoinnsoft SAS',
                'nombre_comercial' => 'Tecnoinnsoft',
                'direccion' => 'Calle 123 #45-67',
                'ciudad_cod' => '05001',
                'dominio' => 'tecnoinnsoft.com',
                'estado' => 'Activo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Tecnoinnsoft SAS')
            ->assertJsonPath('data.identificacion', '900123456');
    }

    #[Test]
    public function it_shows_an_entidad(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/entidad/'.$entidad->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $entidad->id);
    }

    #[Test]
    public function it_updates_an_entidad(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/entidad/'.$entidad->id, [
                'nombre' => 'Updated Corp SAS',
                'identificacion' => $entidad->identificacion,
                'tipo_persona' => $entidad->tipo_persona,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nombre', 'Updated Corp SAS');
    }

    #[Test]
    public function it_deletes_an_entidad(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        $entidad = Entidad::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/entidad/'.$entidad->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_searches_entidades_by_name(): void
    {
        $token = $this->authenticate();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);
        Entidad::factory()->create(['nombre' => 'Tech Corp', 'identificacion' => '111111111']);
        Entidad::factory()->create(['nombre' => 'Finance Ltd', 'identificacion' => '222222222']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/entidad?search=Tech');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('Tech Corp', $data[0]['nombre']);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/entidad', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_entidad(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/entidad/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    /**
     * Helper: crea un usuario con rol Comercial + token + permisos (igual que prod).
     */
    private function authenticateAsComercial(int $userId = 100): array
    {
        $rol = Rol::where('nombre', 'Comercial')->first()
            ?? Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);

        // El PermisoSeeder asigna a Comercial todos los permisos de `entidad`.
        // Replicamos esto en el test para no caer en el silent-block del
        // RbacMiddleware (que devuelve data:null en vez de 403 para GETs).
        foreach (['index', 'store', 'show', 'update', 'destroy'] as $action) {
            Permiso::firstOrCreate([
                'rol_id' => $rol->id,
                'vista' => "entidad.{$action}",
            ]);
        }

        $usuario = Usuario::create([
            'id' => $userId,
            'nombre' => 'Comercial Test',
            'email' => "comercial{$userId}@test.com",
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return [
            'usuario' => $usuario,
            'token' => $usuario->createToken('test-token')->plainTextToken,
        ];
    }

    #[Test]
    public function comercial_can_view_entity_they_are_assigned_to(): void
    {
        $comercial = $this->authenticateAsComercial();
        Ciudad::create(['cod_municipio' => '05001', 'nombre' => 'Medellín', 'departamento' => 'Antioquia']);

        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Empresa Asignada',
            'estado' => 'Prospecto',
        ]);
        DB::table('entidad_usuario')->insert([
            'entidad_id' => $entidad->id,
            'usuario_id' => $comercial['usuario']->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$comercial['token'])
            ->getJson("/api/v1/entidad/{$entidad->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $entidad->id);
    }

    #[Test]
    public function comercial_cannot_view_entity_they_are_not_assigned_to(): void
    {
        $comercial = $this->authenticateAsComercial();
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Empresa NO Asignada',
            'estado' => 'Prospecto',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$comercial['token'])
            ->getJson("/api/v1/entidad/{$entidad->id}");

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function comercial_cannot_update_entity_they_are_not_assigned_to(): void
    {
        $comercial = $this->authenticateAsComercial();
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Empresa NO Asignada',
            'estado' => 'Prospecto',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$comercial['token'])
            ->putJson("/api/v1/entidad/{$entidad->id}", [
                'tipo_persona' => 'Natural',
                'nombre' => 'Intento de update',
                'estado' => 'Prospecto',
            ]);

        $response->assertStatus(403);

        // Verificar que NO se actualizó
        $this->assertEquals('Empresa NO Asignada', $entidad->fresh()->nombre);
    }

    #[Test]
    public function comercial_cannot_delete_entity_they_are_not_assigned_to(): void
    {
        $comercial = $this->authenticateAsComercial();
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Empresa NO Asignada',
            'estado' => 'Prospecto',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$comercial['token'])
            ->deleteJson("/api/v1/entidad/{$entidad->id}");

        $response->assertStatus(403);

        // Verificar que NO se borró
        $this->assertDatabaseHas('entidad', ['id' => $entidad->id]);
    }

    #[Test]
    public function admin_can_still_view_any_entity(): void
    {
        // Admin (rol != Comercial) debe seguir viendo todas las entidades
        $adminToken = $this->authenticate();
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Cualquier Entidad',
            'estado' => 'Prospecto',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson("/api/v1/entidad/{$entidad->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $entidad->id);
    }
}
