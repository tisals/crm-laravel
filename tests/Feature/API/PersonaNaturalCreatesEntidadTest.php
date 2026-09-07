<?php

namespace Tests\Feature\API;

use App\Domain\Events\PersonaChanged;
use App\Domain\Repositories\PersonaRepositoryInterface;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Persona as PersonaModel;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-K — R-iter4.R08 Natural → entidad inversion (REQ-PNCE-001..005).
 *
 * Strict TDD: tests 6c.1..6c.6 are RED before GREEN tasks 6c.7..6c.8.
 *
 * The inversion inverts the legacy convention: a `Persona Natural` (POST
 * without an explicit `entidad_id`) implicitly creates an `entidad` row
 * with the person's `nombre+' '+apellidos` and links the persona to it.
 * The inverse must be ATOMIC — if either insert fails, neither persists
 * — and must dispatch EXACTLY ONE `PersonaChanged` event, not two.
 *
 * Backward compatibility (REQ-PNCE-002 / R-8):
 *  - Explicit `entidad_id` → use it as-is, no new entidad.
 *  - `tipo_persona='juridica'` without `entidad_id` → 422 (existing rule).
 *  - `tipo_persona='juridica'` with `entidad_id` → 201, no new entidad.
 */
class PersonaNaturalCreatesEntidadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Builds an admin user (wildcard `vista='*'` permission) so the
     * persona REST surface skips tenant checks. Mirrors the fixture
     * helper used by `PersonaControllerTest` / `PersonaWebhookEmitterTest`.
     *
     * @return array{usuario: Usuario, token: string}
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
            'token' => $usuario->createToken('test-token')->plainTextToken,
        ];
    }

    // ── 6c.1 RED — natural + no entidad_id auto-creates an entidad ─────

    #[Test]
    public function post_natural_without_entidad_id_creates_a_new_entidad_and_links_the_persona_to_it(): void
    {
        $auth = $this->makeAdmin();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'tipo_persona' => 'natural',
                'nombres' => 'Juan',
                'apellidos' => 'Pérez',
                'email_principal' => 'juan@acme.test',
                'identificacion_tipo' => 'CC',
                'identificacion_numero' => '12345',
            ]);

        $r->assertStatus(201)
            ->assertJsonPath('success', true);

        $newPersonaId = (int) $r->json('data.id');
        $newEntidadId = (int) $r->json('data.entidad_id');
        $this->assertNotNull($newPersonaId);
        $this->assertNotNull($newEntidadId, 'entidad_id must be in response (top-level)');

        // The new entidad exists with the documented shape (REQ-PNCE-001).
        $entidad = Entidad::query()->where('id', $newEntidadId)->first();
        $this->assertNotNull($entidad, 'Entidad row must exist after natural inversion');
        $this->assertSame('Natural', $entidad->tipo_persona);
        $this->assertSame('CC', $entidad->tipo_id);
        $this->assertSame('12345', $entidad->identificacion);
        $this->assertSame('Juan Pérez', $entidad->nombre);
        $this->assertNull($entidad->nombre_comercial);
        // Commit 5.5 dropped `entidad.estado`; the canonical active
        // state is derived from `entidad_relacion` (open pivot row).
        $this->assertSame('activo', $entidad->estado);

        // The persona row points at the new entidad.
        $persona = PersonaModel::query()->where('id', $newPersonaId)->first();
        $this->assertNotNull($persona);
        $this->assertSame($newEntidadId, (int) $persona->entidad_id);
    }

    // ── 6c.2 RED — explicit entidad_id is honored, no new entidad ──────

    #[Test]
    public function post_with_explicit_entidad_id_links_to_that_entidad_and_does_not_create_a_new_one(): void
    {
        $auth = $this->makeAdmin();

        $existing = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Acme Existing',
            'identificacion' => 'ACME-'.uniqid(),
            'estado' => 'Activo',
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'tipo_persona' => 'natural',
                'nombres' => 'María',
                'apellidos' => 'López',
                'email_principal' => 'maria@acme.test',
                'entidad_id' => $existing->id,
            ]);

        $r->assertStatus(201);
        $newPersonaId = (int) $r->json('data.id');
        $this->assertSame($existing->id, (int) $r->json('data.entidad_id'));

        // The persona points at the existing entidad — no new entidad row.
        $persona = PersonaModel::query()->where('id', $newPersonaId)->first();
        $this->assertSame($existing->id, (int) $persona->entidad_id);

        // The count of entidades with this nombre stayed at 1 (no inversion).
        $this->assertSame(
            1,
            Entidad::query()->where('id', $existing->id)->count(),
            'Explicit entidad_id must not create a new entidad row'
        );
    }

    // ── 6c.3 RED — juridica without entidad_id is rejected; with id it works

    #[Test]
    public function post_juridica_without_entidad_id_returns_422(): void
    {
        $auth = $this->makeAdmin();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'tipo_persona' => 'juridica',
                'nombres' => 'Acme Corp',
                'email_principal' => 'legal@acme.test',
                // no entidad_id
            ]);

        $r->assertStatus(422)
            ->assertJsonValidationErrors(['entidad_id']);
    }

    #[Test]
    public function post_juridica_with_entidad_id_succeeds_and_does_not_create_a_new_entidad(): void
    {
        $auth = $this->makeAdmin();

        $existing = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Acme Juridica',
            'identificacion' => 'JUR-'.uniqid(),
            'estado' => 'Activo',
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'tipo_persona' => 'juridica',
                'nombres' => 'Contact Person',
                'email_principal' => 'contact@acme.test',
                'entidad_id' => $existing->id,
            ]);

        $r->assertStatus(201);
        $this->assertSame($existing->id, (int) $r->json('data.entidad_id'));

        // No new entidad row was created.
        $this->assertSame(
            1,
            Entidad::query()->where('id', $existing->id)->count()
        );
    }

    // ── 6c.4 RED — atomicity: persona-insert failure rolls back entidad ─

    #[Test]
    public function post_natural_inversion_rolls_back_the_new_entidad_when_persona_insert_fails(): void
    {
        $auth = $this->makeAdmin();

        // Snapshot entidad count BEFORE the request so the atomicity
        // assertion is unambiguous: zero new entidad rows must remain
        // after the failure.
        $entidadCountBefore = (int) Entidad::query()->count();

        // Force the persona-insert step to fail AFTER the inversion has
        // already created the entidad. The transaction MUST roll back,
        // otherwise the entidad stays orphaned. Using a Mockery spy on
        // the repository keeps the failure injection close to the unit
        // boundary (no model-event hacks, no global state leakage).
        $repo = Mockery::mock(PersonaRepositoryInterface::class);
        $repo->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException('forced persona insert failure for atomicity test'));
        $this->app->instance(PersonaRepositoryInterface::class, $repo);

        // The POST will 500 because the inner exception bubbles up; the
        // outer RefreshDatabase transaction stays open so the assertion
        // below can inspect the post-failure state.
        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'tipo_persona' => 'natural',
                'nombres' => 'Atomic',
                'apellidos' => 'Fail',
                'email_principal' => 'atomic@fail.test',
            ])
            ->assertStatus(500);

        // No orphan entidad was left behind. Without `DB::transaction`
        // wrapping the inversion + persona insert, the entidad would
        // have committed and this count would be +1.
        $this->assertSame(
            $entidadCountBefore,
            (int) Entidad::query()->count(),
            'The inversion entidad must roll back when the persona insert fails'
        );
    }

    // ── 6c.5 RED — omitting tipo_persona defaults to 'natural' and inverts

    #[Test]
    public function post_without_tipo_persona_defaults_to_natural_and_triggers_the_inversion(): void
    {
        $auth = $this->makeAdmin();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                // no tipo_persona — default is 'natural' per REQ-PNCE-005
                'nombres' => 'Default',
                'apellidos' => 'Natural',
                'email_principal' => 'default@natural.test',
                'identificacion_tipo' => 'CC',
                'identificacion_numero' => '8888',
            ]);

        $r->assertStatus(201);

        $newPersonaId = (int) $r->json('data.id');
        $newEntidadId = (int) $r->json('data.entidad_id');
        $this->assertNotNull($newEntidadId, 'Omitting tipo_persona must trigger inversion');

        // Stored tipo_persona on the persona row should be the canonical
        // 'Natural' (uppercase first letter) — the DB column is
        // ENUM('Natural','Juridica'), so the canonical mapping is the
        // only one the schema accepts.
        $persona = PersonaModel::query()->where('id', $newPersonaId)->first();
        $this->assertSame('Natural', $persona->tipo_persona);

        // The inversion entidad exists and links back to the persona.
        $entidad = Entidad::query()->where('id', $newEntidadId)->first();
        $this->assertNotNull($entidad);
        $this->assertSame('Natural', $entidad->tipo_persona);
        $this->assertSame('Default Natural', $entidad->nombre);
        $this->assertSame($newEntidadId, (int) $persona->entidad_id);
    }

    // ── 6c.6 RED — inversion emits exactly one PersonaChanged event ────

    #[Test]
    public function natural_inversion_dispatches_exactly_one_persona_changed_with_the_final_entidad_id(): void
    {
        $auth = $this->makeAdmin();

        Event::fake();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'tipo_persona' => 'natural',
                'nombres' => 'Single',
                'apellidos' => 'Event',
                'email_principal' => 'single@event.test',
                'identificacion_tipo' => 'CC',
                'identificacion_numero' => '9999',
            ]);

        $r->assertStatus(201);
        $newPersonaId = (int) $r->json('data.id');
        $finalEntidadId = (int) $r->json('data.entidad_id');
        $this->assertNotNull($finalEntidadId);

        // Exactly ONE PersonaChanged event, with the post-commit snapshot
        // carrying the FINAL entidad_id (not NULL). If the implementation
        // dispatched one for the entidad and one for the persona (or
        // captured the pre-inversion snapshot), this assertion catches it.
        Event::assertDispatched(PersonaChanged::class, 1);
        Event::assertDispatched(PersonaChanged::class, function (PersonaChanged $event) use ($newPersonaId, $finalEntidadId) {
            return $event->action === 'created'
                && $event->persona_id === $newPersonaId
                && (int) $event->snapshot['entidad_id'] === $finalEntidadId;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
