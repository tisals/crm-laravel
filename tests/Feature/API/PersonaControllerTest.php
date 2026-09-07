<?php

namespace Tests\Feature\API;

use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Persona as PersonaModel;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Administrativo\Models\Colaborador;
use Modules\Administrativo\Models\Proveedor;
use Modules\CRM\Models\Contacto;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-I: persona REST surface (REQ-PRAPI-001..006, OI-4, OI-5).
 *
 * Strict TDD: tests 6a.1..6a.7 are RED before GREEN tasks 6a.8..6a.14
 * (PersonaStoreRequest / PersonaUpdateRequest split, route PUT→PATCH,
 * resource relations block, ShowPersonaUseCase tenant check, repo OI-6 fix).
 *
 * The fixture pair `makeAdmin` (wildcard `vista='*'`) and `makeScopedUser`
 * (per-vista permissions) lets us exercise the admin-bypass path on the
 * tenant check vs. the strict non-admin path (REQ-PRAPI-003).
 */
class PersonaControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Builds a user with a wildcard `vista='*'` permission. This routes
     * `personas.store|show|update|destroy` AND bypasses the tenant-isolation
     * check in `ShowPersonaUseCase` (OI-4 admin fast-path).
     */
    private function makeAdmin(): array
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin-'.uniqid().'@test.local',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return [
            'usuario' => $usuario,
            'rol' => $rol,
            'token' => $usuario->createToken('test-token')->plainTextToken,
        ];
    }

    /**
     * Builds a non-admin user with explicit per-route permissions. No
     * wildcard → tenant check applies (REQ-PRAPI-003).
     */
    private function makeScopedUser(): array
    {
        $rol = Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);
        foreach (['personas.index', 'personas.store', 'personas.show', 'personas.update', 'personas.destroy'] as $v) {
            Permiso::create(['rol_id' => $rol->id, 'vista' => $v]);
        }

        $usuario = Usuario::create([
            'nombre' => 'Scoped User',
            'email' => 'scoped-'.uniqid().'@test.local',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return [
            'usuario' => $usuario,
            'rol' => $rol,
            'token' => $usuario->createToken('test-token')->plainTextToken,
        ];
    }

    private function bindUsuarioToEntidad(Usuario $usuario, int $entidadId): void
    {
        // Per commit fe99f70: pivot is `entidad_persona` keyed on
        // usuarios.persona_id (NOT NULL FK added in migration 000003).
        DB::table('entidad_persona')->insert([
            'persona_id' => $usuario->persona_id,
            'entidad_id' => $entidadId,
            'categoria' => 'asignacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── 6a.1 RED — POST happy path ──────────────────────────────────────

    #[Test]
    public function post_creates_a_persona_and_returns_201_with_data_id(): void
    {
        $auth = $this->makeAdmin();

        $payload = [
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'email_principal' => 'ada@acme.test',
            'identificacion_tipo' => 'CC',
            'identificacion_numero' => '12345',
            'telefono_principal' => '+57-1-555-1234',
        ];

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', $payload);

        $r->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data' => ['id', 'nombres', 'apellidos', 'email_principal']]);
        $this->assertNotNull($r->json('data.id'));
        $this->assertSame('Ada', $r->json('data.nombres'));
        $this->assertSame('Lovelace', $r->json('data.apellidos'));
        $this->assertSame('ada@acme.test', $r->json('data.email_principal'));
    }

    // ── 6a.2 RED — POST validation failure ──────────────────────────────

    #[Test]
    public function post_returns_422_with_field_errors_on_invalid_payload(): void
    {
        $auth = $this->makeAdmin();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'email_principal' => 'not-an-email',
                'tipo_persona' => 'BogusType',
            ]);

        $r->assertStatus(422)
            ->assertJsonValidationErrors(['nombres', 'email_principal', 'tipo_persona']);
    }

    // ── 6a.3 RED — POST duplicate email in same entidad (OI-5) ─────────

    #[Test]
    public function post_rejects_duplicate_email_principal_within_the_same_entidad(): void
    {
        $auth = $this->makeAdmin();
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Acme',
            'identificacion' => 'ACME-DUP-'.uniqid(),
        ]);
        $existing = PersonaModel::create([
            'nombres' => 'Existing',
            'entidad_id' => $entidad->id,
        ]);
        // Commit 4 dropped `personas.email_principal`; the canonical
        // email row lives in the shared `emails` table. Insert it
        // directly so the validator's uniqueness check has a row to
        // collide against.
        DB::table('emails')->insert([
            'persona_id' => $existing->id,
            'email' => 'shared@acme.test',
            'tipo' => 'personal',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // The validator's uniqueness check scopes to the `entidad_id`
        // via `entidad_persona` pivot. Without this row the existing
        // email does not register as in-entidad and the duplicate
        // guard silently passes.
        DB::table('entidad_persona')->insertOrIgnore([
            'persona_id' => $existing->id,
            'entidad_id' => $entidad->id,
            'categoria' => 'asignacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'nombres' => 'New',
                'email_principal' => 'shared@acme.test',
                'entidad_id' => $entidad->id,
            ]);

        $r->assertStatus(422)->assertJsonValidationErrors(['email_principal']);
    }

    // ── 6a.4 RED — GET returns persona + relations block ───────────────

    #[Test]
    public function get_returns_persona_with_relations_block(): void
    {
        $auth = $this->makeAdmin();
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Acme',
            'identificacion' => 'ACME-REL-'.uniqid(),
            'estado' => 'Activo',
        ]);
        $persona = PersonaModel::create([
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'email_principal' => 'ada@acme.test',
            'entidad_id' => $entidad->id,
        ]);
        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'persona_id' => $persona->id,
            'estado' => 'Activo',
            'score' => 0,
        ]);
        // One contacto (no colaborador, no proveedor) — relations block carries nulls there.
        $this->assertInstanceOf(Contacto::class, $contacto);

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->getJson("/api/v1/personas/{$persona->id}");

        $r->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $persona->id)
            ->assertJsonPath('data.nombres', 'Ada')
            ->assertJsonPath('data.entidad_id', $entidad->id)
            ->assertJsonPath('data.tipo_persona', 'Natural')
            ->assertJsonPath('data.relations.contacto_id', $contacto->id)
            ->assertJsonPath('data.relations.colaborador_id', null)
            ->assertJsonPath('data.relations.proveedor_id', null)
            ->assertJsonPath('data.relations.entidad_id', $entidad->id);
    }

    // ── 6a.5 RED — GET 404 / 403 ───────────────────────────────────────

    #[Test]
    public function get_returns_404_for_unknown_persona(): void
    {
        $auth = $this->makeAdmin();
        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->getJson('/api/v1/personas/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function get_returns_403_when_persona_belongs_to_a_different_entidad_for_non_admin(): void
    {
        $auth = $this->makeScopedUser();
        $userEntidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Mine',
            'identificacion' => 'MINE-'.uniqid(),
            'estado' => 'Activo',
        ]);
        $otherEntidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Other',
            'identificacion' => 'OTHER-'.uniqid(),
            'estado' => 'Activo',
        ]);
        $persona = PersonaModel::create([
            'nombres' => 'Tenant Lockout',
            'entidad_id' => $otherEntidad->id,
        ]);
        // Bind the user to userEntidad (NOT otherEntidad) so the non-admin
        // tenant check must reject the cross-entidad read (REQ-PRAPI-003).
        $this->bindUsuarioToEntidad($auth['usuario'], $userEntidad->id);

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->getJson("/api/v1/personas/{$persona->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    // ── 6a.6 RED — PATCH partial update ────────────────────────────────

    #[Test]
    public function patch_updates_only_the_provided_field_and_returns_200(): void
    {
        $auth = $this->makeAdmin();
        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Acme',
            'identificacion' => 'ACME-PATCH-'.uniqid(),
        ]);
        $persona = PersonaModel::create([
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'entidad_id' => $entidad->id,
        ]);
        // Commit 4 dropped `personas.email_principal`; the canonical
        // email row lives in the shared `emails` table.
        DB::table('emails')->insert([
            'persona_id' => $persona->id,
            'email' => 'before@acme.test',
            'tipo' => 'personal',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Only `email_principal` is sent — `nombres`/`apellidos` must NOT
        // flip to null or 422. The other fields must come back unchanged.
        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->patchJson("/api/v1/personas/{$persona->id}", ['email_principal' => 'after@acme.test']);

        $r->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email_principal', 'after@acme.test')
            ->assertJsonPath('data.nombres', 'Ada')
            ->assertJsonPath('data.apellidos', 'Lovelace');
    }

    // ── 6a.7 RED — 401 without token; routes resolve under /api/v1/personas

    #[Test]
    public function all_three_persona_endpoints_return_401_without_token(): void
    {
        $this->postJson('/api/v1/personas', ['nombres' => 'NoAuth'])->assertStatus(401);
        $this->getJson('/api/v1/personas/1')->assertStatus(401);
        $this->patchJson('/api/v1/personas/1', ['nombres' => 'NoAuth'])->assertStatus(401);
    }

    #[Test]
    public function routes_resolve_under_api_v1_personas_prefix(): void
    {
        // Spot-check the route name → URL binding. PR-I changes
        // `PUT /personas/{id}` → `PATCH /personas/{id}` (spec REQ-PRAPI-004)
        // while preserving the `personas.update` name. The 3rd-arg `false`
        // asks for the path-only URI (no scheme/host), which is what we
        // care about for the prefix assertion.
        $this->assertSame('/api/v1/personas', route('personas.index', [], false));
        $this->assertSame('/api/v1/personas', route('personas.store', [], false));
        $this->assertSame('/api/v1/personas/42', route('personas.show', ['id' => 42], false));
        $this->assertSame('/api/v1/personas/42', route('personas.update', ['id' => 42], false));
        $this->assertSame('/api/v1/personas/42', route('personas.destroy', ['id' => 42], false));
    }
}
