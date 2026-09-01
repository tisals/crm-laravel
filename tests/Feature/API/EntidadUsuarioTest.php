<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
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

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture: EntidadFactory hard-codes ciudad_cod = '05001'; seed the
        // matching Ciudad row so the FK on `entidad.ciudad_cod` is satisfied.
        // (Pre-existing fixture issue documented in
        //  Docs/changes/multi-app-access/verify-report-v2.md.)
        if (! Ciudad::where('cod_municipio', '05001')->exists()) {
            Ciudad::create([
                'cod_municipio' => '05001',
                'nombre' => 'Medellín',
                'departamento' => 'Antioquia',
            ]);
        }
    }

    private function createAdminUser(): array
    {
        return $this->createUsuario('Admin', 'admin@test.com');
    }

    private function createOperacionesUser(): array
    {
        return $this->createUsuario('Operaciones', 'ops@test.com');
    }

    /**
     * Create a usuario + matching persona row.
     *
     * Per `tenant-data-model-fixes` (commit 2.5) + migration 000003:
     * `usuarios.persona_id` is NOT NULL. Each usuario is backed by a
     * `personas` row, so the helper creates the persona first and stamps
     * it on the usuario.
     */
    private function createUsuario(string $rolNombre, string $email): array
    {
        $rol = Rol::create(['nombre' => $rolNombre, 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        // Per migration 000003: each usuario is backed by a persona.
        // (`tipo_persona` ENUM was dropped in migration 2026_08_29_000001.)
        $persona = \App\Models\Persona::create([
            'email_principal' => $email,
            'nombres' => $rolNombre,
        ]);

        $usuario = Usuario::create([
            'nombre' => $rolNombre.' User',
            'email' => $email,
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
            'persona_id' => $persona->id,
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
        $comercial = $this->createUsuario('Comercial', 'comercial.assign@test.com');

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad-usuario', [
                'usuario_id' => $comercial['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Per `tenant-data-model-fixes` (commit 2.5): pivot is now
        // `entidad_persona` keyed on `persona_id` (not `usuario_id`).
        $this->assertDatabaseHas('entidad_persona', [
            'persona_id' => $comercial['usuario']->persona_id,
            'entidad_id' => $entidad->id,
        ]);
    }

    #[Test]
    public function admin_can_deassign_user_from_entity(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $comercial = $this->createUsuario('Comercial', 'comercial.deassign@test.com');

        // First assign (insert pivot directly since the relation is
        // hasManyThrough, not belongsToMany).
        \DB::table('entidad_persona')->insert([
            'persona_id' => $comercial['usuario']->persona_id,
            'entidad_id' => $entidad->id,
            'categoria' => 'asignacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Then deassign
        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->deleteJson('/api/v1/entidad-usuario', [
                'usuario_id' => $comercial['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('entidad_persona', [
            'persona_id' => $comercial['usuario']->persona_id,
            'entidad_id' => $entidad->id,
        ]);
    }

    #[Test]
    public function admin_can_list_users_for_entity(): void
    {
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $comercial = $this->createUsuario('Comercial', 'comercial.list@test.com');

        // Insert pivot row directly (relation is hasManyThrough, not
        // belongsToMany).
        \DB::table('entidad_persona')->insert([
            'persona_id' => $comercial['usuario']->persona_id,
            'entidad_id' => $entidad->id,
            'categoria' => 'asignacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->getJson('/api/v1/entidad/'.$entidad->id.'/usuarios');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['id' => $comercial['usuario']->id]);
    }

    #[Test]
    public function comercial_user_can_be_assigned(): void
    {
        // Per the controller's allowlist (Comercial/SuperAdmin): the original
        // test used 'Ventas' (a legacy role that no longer exists in the
        // roles table). Replaced with 'Comercial' which IS in the allowlist.
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $comercial = $this->createUsuario('Comercial', 'comercial@test.com');

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad-usuario', [
                'usuario_id' => $comercial['usuario']->id,
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
        $comercial = $this->createUsuario('Comercial', 'comercial.dup@test.com');

        // First assignment via direct pivot insert (relation is
        // hasManyThrough, not belongsToMany).
        \DB::table('entidad_persona')->insert([
            'persona_id' => $comercial['usuario']->persona_id,
            'entidad_id' => $entidad->id,
            'categoria' => 'asignacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Try to assign again
        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad-usuario', [
                'usuario_id' => $comercial['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(409);
    }

    #[Test]
    public function deassign_nonexistent_returns_idempotent_success(): void
    {
        // Per the new EntidadPersonaController::destroy: returns 200 with
        // an idempotency message when no assignment exists (matches the
        // original EntidadUsuarioController behavior documented in the
        // legacy allowlist fix).
        $auth = $this->createAdminUser();
        $entidad = Entidad::factory()->create();
        $comercial = $this->createUsuario('Comercial', 'comercial2@test.com');

        $response = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->deleteJson('/api/v1/entidad-usuario', [
                'usuario_id' => $comercial['usuario']->id,
                'entidad_id' => $entidad->id,
            ]);

        $response->assertStatus(200);
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
