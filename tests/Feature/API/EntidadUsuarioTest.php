<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EntidadUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminUser(): array
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

        return [
            'usuario' => $usuario,
            'token' => $usuario->createToken('test-token')->plainTextToken,
        ];
    }

    private function createVentasUser(): array
    {
        $rol = Rol::create(['nombre' => 'Ventas', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Ventas User',
            'email' => 'ventas@test.com',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return [
            'usuario' => $usuario,
            'token' => $usuario->createToken('test-token')->plainTextToken,
        ];
    }

    private function createOperacionesUser(): array
    {
        $rol = Rol::create(['nombre' => 'Operaciones', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Operaciones User',
            'email' => 'ops@test.com',
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
    public function admin_can_assign_user_to_entity(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $ventas = $this->createVentasUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad-usuario', [
                'usuario_id' => $ventas['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('entidad_usuario', [
            'usuario_id' => $ventas['usuario']->id,
            'entidad_id' => $entidad->id,
        ]);
    }

    #[Test]
    public function admin_can_deassign_user_from_entity(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $ventas = $this->createVentasUser();

        // First assign
        $entidad->usuarios()->attach($ventas['usuario']->id);

        // Then deassign
        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->deleteJson('/api/v1/entidad-usuario', [
                'usuario_id' => $ventas['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('entidad_usuario', [
            'usuario_id' => $ventas['usuario']->id,
            'entidad_id' => $entidad->id,
        ]);
    }

    #[Test]
    public function admin_can_list_users_for_entity(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $ventas = $this->createVentasUser();

        $entidad->usuarios()->attach($ventas['usuario']->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->getJson('/api/v1/entidad/'.$entidad->id.'/usuarios');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => $ventas['usuario']->id]);
    }

    #[Test]
    public function ventas_user_can_be_assigned(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $ventas = $this->createVentasUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad-usuario', [
                'usuario_id' => $ventas['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function operaciones_user_cannot_be_assigned(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $ops = $this->createOperacionesUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad-usuario', [
                'usuario_id' => $ops['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function duplicate_assignment_returns_409(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $ventas = $this->createVentasUser();

        // First assignment
        $entidad->usuarios()->attach($ventas['usuario']->id);

        // Try to assign again
        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad-usuario', [
                'usuario_id' => $ventas['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(409);
    }

    #[Test]
    public function deassign_nonexistent_returns_404(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $ventas = $this->createVentasUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->deleteJson('/api/v1/entidad-usuario', [
                'usuario_id' => $ventas['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/entidad-usuario', [
            'usuario_id' => 1,
            'entidad_id' => 1,
        ]);

        $response->assertStatus(401);
    }
}
