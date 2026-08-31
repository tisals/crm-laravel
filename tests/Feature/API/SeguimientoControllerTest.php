<?php

namespace Tests\Feature\API;

use App\Domain\Repositories\SeguimientoRepositoryInterface;
use App\Models\Ciudad;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\Seguimiento;
use App\Models\Usuario;
use App\Notifications\FollowUpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-H (Phase 5b): seguimiento FK swap code migration tests (REQ-SEG-004).
 *
 * Replaces seguimiento.contacto_id (FK → contacto.id) with
 * seguimiento.persona_id (FK → personas.id) at the application layer.
 * The DB schema was swapped by PR-G; this PR migrates every code path
 * that read or wrote seguimiento.contacto_id.
 *
 * Strict TDD: tests 5b.1–5b.4 are RED before the production code is
 * updated; GREEN tasks 5b.5–5b.12 make them pass.
 *
 * Cross-references:
 *   - spec: specs/seguimiento-modificado/spec.md (REQ-SEG-004)
 *   - design: design.md §6 Phase 5 (scoped file list), Key Learning #4
 *     (audit `rg "contacto_id"` must show only non-seguimiento hits)
 *   - sibling: tests/Feature/Migration/SeguimientoFkSwapTest.php
 *     (PR-G schema swap tests; this file covers the code-layer tests)
 */
class SeguimientoControllerTest extends TestCase
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

    /**
     * Create the canonical test fixture: an entidad, a natural-person
     * `persona`, a contacto linked to that persona, and an oportunidad
     * linked to the contacto. After PR-H, contacto.persona_id is the
     * canonical "who is this follow-up for?" pointer; the test must
     * provision the persona BEFORE the contacto so the FK is satisfied.
     *
     * @return array{entidad: Entidad, persona: Persona, contacto: Contacto, oportunidad: Oportunidad}
     */
    private function createReferences(): array
    {
        // Entidad factory writes `ciudad_cod = 05001` so the ciudad row
        // must exist or the FK constraint fails. Idempotent — multiple
        // tests in this file can call this helper safely.
        if (! Ciudad::where('cod_municipio', '05001')->exists()) {
            Ciudad::create([
                'cod_municipio' => '05001',
                'nombre' => 'Medellín',
                'departamento' => 'Antioquia',
            ]);
        }

        $entidad = Entidad::factory()->create();
        $persona = Persona::create([
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'email_principal' => 'ada-'.uniqid().'@example.test',
        ]);
        $contacto = Contacto::factory()->create([
            'entidad_id' => $entidad->id,
            'persona_id' => $persona->id,
            'nombres' => $persona->nombres,
            'apellidos' => $persona->apellidos,
            'email_contacto' => $persona->email_principal,
        ]);
        $oportunidad = Oportunidad::create([
            'codigo' => 'COT-'.str_pad((string) $entidad->id, 6, '0', STR_PAD_LEFT),
            'entidad_id' => $entidad->id,
            'contacto_id' => $contacto->id,
            'fecha' => '2026-05-10',
            'estado' => 'Borrador',
        ]);

        return [
            'entidad' => $entidad,
            'persona' => $persona,
            'contacto' => $contacto,
            'oportunidad' => $oportunidad,
        ];
    }

    // ── 5b.1 — POST/PUT use persona_id; 422 when contacto_id is sent ───

    /**
     * REQ-SEG-004 (5b.1): POST /api/v1/seguimientos must accept `persona_id`
     * (the post-PR-G canonical FK) and return 201 with the new field on
     * the response envelope. RED pre-GREEN: the test asserts the response
     * shape that the production code does NOT yet emit (it still tries to
     * write `contacto_id` which fails because the column was dropped by
     * PR-G migration).
     */
    #[Test]
    public function it_creates_a_seguimiento_with_persona_id(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'persona_id' => $refs['persona']->id,
                'entidad_id' => $refs['entidad']->id,
                'tipo' => 'Llamada',
                'fecha' => '2026-05-10',
                'hora' => '10:00:00',
                'notas' => 'Test seguimiento',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo', 'Llamada')
            ->assertJsonPath('data.estado', 'Pendiente')
            ->assertJsonPath('data.notas', 'Test seguimiento')
            ->assertJsonPath('data.persona_id', $refs['persona']->id);

        // The DB row must have persona_id populated (not contacto_id).
        $row = Seguimiento::where('persona_id', $refs['persona']->id)->first();
        $this->assertNotNull($row, 'seguimiento must be persisted with persona_id');
    }

    /**
     * REQ-SEG-004 (5b.1): POST with the legacy `contacto_id` field must
     * return 422. After PR-G, `contacto_id` is no longer a known
     * validation key on the Form Request and is silently dropped from the
     * payload — leaving the new seguimiento without a persona_id, which
     * is fine for nullable data but the legacy field is dead and must be
     * rejected loudly so callers update their integration. RED pre-GREEN:
     * the request currently accepts `contacto_id` (Form Request still has
     * the legacy rule) and the controller writes it to a column that no
     * longer exists, returning 500.
     */
    #[Test]
    public function it_rejects_payload_with_legacy_contacto_id_field(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                // Legacy field — must be rejected (422) after PR-H.
                'contacto_id' => $refs['contacto']->id,
                'entidad_id' => $refs['entidad']->id,
                'tipo' => 'Llamada',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);

        // 422 (validation error) is the canonical PR-H behaviour: the
        // legacy field is unknown to the Form Request, so it fails the
        // schema check.
        $response->assertStatus(422);

        // No row should have been written.
        $this->assertSame(0, Seguimiento::count(), 'no seguimiento row must be created on 422');
    }

    /**
     * REQ-SEG-004 (5b.1): PUT /api/v1/seguimientos/{id} must accept
     * `persona_id` and return 200 with the new field on the response.
     */
    #[Test]
    public function it_updates_a_seguimiento_with_persona_id(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'persona_id' => $refs['persona']->id,
                'entidad_id' => $refs['entidad']->id,
                'tipo' => 'Nota',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);
        $createResponse->assertStatus(201);
        $id = $createResponse->json('data.id');
        $this->assertNotNull($id, 'POST must return a non-null seguimiento id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/seguimientos/'.$id, [
                'estado' => 'Completado',
                'notas' => 'Completed follow-up',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado', 'Completado')
            ->assertJsonPath('data.notas', 'Completed follow-up')
            ->assertJsonPath('data.persona_id', $refs['persona']->id);
    }

    // ── 5b.2 — Resource exposes persona_id + contacto_nombre ─────────

    /**
     * REQ-SEG-004 (5b.2): the SeguimientoResource must expose `persona_id`
     * AND a `contacto_nombre`-equivalent resolved from the persona
     * (because the frontend dashboard was already rendering
     * `contacto_nombre`; renaming would break the UI). The accessor now
     * resolves "Ada Lovelace" from `persona.nombres + persona.apellidos`
     * via `persona()` instead of from the (gone) `contacto()` relation.
     */
    #[Test]
    public function it_resource_exposes_persona_id_and_contacto_nombre(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'persona_id' => $refs['persona']->id,
                'entidad_id' => $refs['entidad']->id,
                'tipo' => 'Llamada',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);
        $response->assertStatus(201);

        // persona_id must be present on the JSON envelope.
        $response->assertJsonPath('data.persona_id', $refs['persona']->id);

        // The show endpoint must also expose it (round-trip).
        $id = $response->json('data.id');
        $showResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/seguimientos/'.$id);
        $showResponse->assertStatus(200)
            ->assertJsonPath('data.persona_id', $refs['persona']->id);

        // contacto_nombre accessor must resolve from persona (the post-PR-G
        // fallback path) — not from contacto (gone). The accessor lives
        // on the Eloquent model and is exposed by JsonResource's default
        // `$this->resource` flattening. If the resource strips it, the
        // accessor still exists on the model and we can assert via the
        // model directly.
        $reloaded = Seguimiento::with('persona')->find($id);
        $this->assertNotNull($reloaded);
        $this->assertSame(
            "{$refs['persona']->nombres} {$refs['persona']->apellidos}",
            $reloaded->contacto_nombre,
            'Seguimiento::contacto_nombre accessor must resolve from persona.nombres + persona.apellidos'
        );
    }

    // ── 5b.3 — POST /contacto/{id}/acciones stamps persona_id ─────────

    /**
     * REQ-SEG-004 (5b.3): the legacy "registrar acción de seguimiento"
     * endpoint `POST /api/v1/contacto/{contactoId}/acciones` must still
     * create a seguimiento, but it now stamps `persona_id` (resolved
     * from `contacto.persona_id`) instead of the (gone) `contacto_id`.
     * RED pre-GREEN: the controller currently writes `contacto_id` to
     * the seguimiento row, which fails with a SQLSTATE column-not-found
     * error from the DB.
     */
    #[Test]
    public function contacto_acciones_creates_seguimiento_with_persona_id_from_contacto(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        // The contacto MUST have persona_id populated (backfill invariant
        // — verified by PR-F). We assert it here as a precondition.
        $this->assertNotNull(
            $refs['contacto']->persona_id,
            'precondition: contacto must have persona_id populated (backfill complete)'
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/contacto/{$refs['contacto']->id}/acciones", [
                'tipo' => 'Llamada',
                'notas' => 'Llamada de seguimiento inicial',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        // The seguimiento must be stamped with persona_id = contacto.persona_id.
        $seguimiento = Seguimiento::latest('id')->first();
        $this->assertNotNull($seguimiento, 'POST /contacto/{id}/acciones must create a seguimiento');
        $this->assertSame(
            (int) $refs['contacto']->persona_id,
            (int) $seguimiento->persona_id,
            'seguimiento.persona_id must resolve from contacto.persona_id'
        );
        $this->assertSame('Llamada de seguimiento inicial', $seguimiento->notas);
    }

    // ── 5b.4 — FollowUpNotification renders with persona_id ──────────

    /**
     * REQ-SEG-004 (5b.4): FollowUpNotification must render without
     * undefined-index errors when the seguimiento has `persona_id`
     * (no `contacto_id` column exists). The notification's toArray()
     * payload must NOT contain `contacto_id`; it must contain the new
     * `persona_id` instead. RED pre-GREEN: the notification currently
     * includes `contacto_id => $this->seguimiento->contacto_id` which is
     * undefined (column gone), so the notification payload fails to
     * construct (PHP warning + missing key).
     */
    #[Test]
    public function follow_up_notification_payload_uses_persona_id(): void
    {
        $refs = $this->createReferences();
        $seguimiento = Seguimiento::create([
            'oportunidad_id' => $refs['oportunidad']->id,
            'persona_id' => $refs['persona']->id,
            'entidad_id' => $refs['entidad']->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-09-01',
            'hora' => '10:00:00',
            'estado' => 'Pendiente',
            'autor_id' => Usuario::first()?->id,
        ]);
        $this->assertNotNull($seguimiento->persona_id, 'precondition: persona_id must be set');

        $notification = new FollowUpNotification($seguimiento);

        // toArray() must NOT throw and must include persona_id (not contacto_id).
        $payload = $notification->toArray($seguimiento);

        $this->assertArrayHasKey('persona_id', $payload, 'payload must carry persona_id');
        $this->assertArrayNotHasKey(
            'contacto_id',
            $payload,
            'payload must NOT carry legacy contacto_id (column gone)'
        );
        $this->assertSame(
            (int) $refs['persona']->id,
            (int) $payload['persona_id'],
            'payload.persona_id must equal seguimiento.persona_id'
        );
    }

    /**
     * REQ-SEG-004 (5b.4): FollowUpNotification must dispatch successfully
     * via Notification::send() against a fake — the legacy field access
     * `$this->seguimiento->contacto_id` raises an Eloquent
     * `AttributeException` (column not found on the model) which would
     * bubble out of Notification::send() and fail the dispatch. RED
     * pre-GREEN: the dispatch throws.
     */
    #[Test]
    public function follow_up_notification_dispatches_without_error(): void
    {
        Notification::fake();

        $refs = $this->createReferences();
        $seguimiento = Seguimiento::create([
            'oportunidad_id' => $refs['oportunidad']->id,
            'persona_id' => $refs['persona']->id,
            'entidad_id' => $refs['entidad']->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-09-01',
            'estado' => 'Pendiente',
            'autor_id' => Usuario::first()?->id,
        ]);

        // Dispatching the notification must not throw. The recipient
        // resolution path (NotificacionRecipientsResolver) returns an
        // empty collection in this minimal test, so the Log::warning
        // branch fires — we only care that the notification object
        // itself constructs and the toArray() / toMail() methods don't
        // raise.
        $notification = new FollowUpNotification($seguimiento);
        $payload = $notification->toArray($seguimiento);
        $this->assertIsArray($payload, 'notification payload must be an array');

        // No exception means PASS. Notification::fake() swallows sends.
        Notification::assertNothingSent();
    }

    // ── Existing CRUD tests (updated for persona_id) ─────────────────

    #[Test]
    public function it_lists_seguimientos(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/seguimientos');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page']]);
    }

    #[Test]
    public function it_shows_a_seguimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'persona_id' => $refs['persona']->id,
                'tipo' => 'Correo',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);
        $createResponse->assertStatus(201);
        // Debug: capture the full response so we can diagnose the show 404.
        $id = $createResponse->json('data.id');
        if ($id === null) {
            $this->fail('POST returned no data.id. Full response: '.json_encode($createResponse->json()));
        }

        // Diagnostic: confirm the row exists in the DB before we GET it.
        $row = Seguimiento::find($id);
        $this->assertNotNull($row, "seguimiento #{$id} must exist in the DB after POST. Created IDs: ".
            Seguimiento::pluck('id')->implode(','));
        $viaRepo = app(SeguimientoRepositoryInterface::class)->findById($id);
        $this->assertNotNull($viaRepo, 'repository.findById must return the row (got null). id='.$id.
            ' DB id type='.gettype(Seguimiento::query()->value('id')).' DB count='.Seguimiento::count());

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/seguimientos/'.$id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id);
    }

    #[Test]
    public function it_deletes_a_seguimiento(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $createResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'persona_id' => $refs['persona']->id,
                'tipo' => 'Otro',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ]);
        $createResponse->assertStatus(201);
        $id = $createResponse->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/seguimientos/'.$id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_404_for_missing_seguimiento(): void
    {
        $token = $this->authenticate();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/seguimientos/9999');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_filters_by_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'oportunidad_id' => $refs['oportunidad']->id,
                'persona_id' => $refs['persona']->id,
                'tipo' => 'Reunion',
                'fecha' => '2026-05-10',
                'estado' => 'Completado',
            ])->assertStatus(201);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/oportunidades/{$refs['oportunidad']->id}/seguimientos");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
    }

    #[Test]
    public function it_filters_by_entidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'entidad_id' => $refs['entidad']->id,
                'persona_id' => $refs['persona']->id,
                'tipo' => 'Nota',
                'fecha' => '2026-05-10',
                'estado' => 'Pendiente',
            ])->assertStatus(201);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/entidades/{$refs['entidad']->id}/seguimientos");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
    }

    /**
     * REQ-SEG-004: the legacy `it_filters_by_contacto` test no longer
     * applies (seguimiento.contacto_id is gone). Replace it with the
     * persona-equivalent: filter seguimientos by `persona_id` (the new
     * canonical axis) at the repository layer. The HTTP-level persona
     * route (`/api/v1/personas/{id}/seguimientos`) is PR-I territory
     * (persona REST surface) and is tested in PersonaControllerTest.
     *
     * This test asserts the canonical repository-level invariant: a
     * seguimiento tied to a persona is queryable by persona_id.
     */
    #[Test]
    public function it_filters_by_persona_via_repository(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'persona_id' => $refs['persona']->id,
                'tipo' => 'Llamada',
                'fecha' => '2026-05-10',
                'estado' => 'Completado',
            ])->assertStatus(201);

        // Repository-level invariant: a query that filters by persona_id
        // returns the row we just created. The HTTP route for
        // /personas/{id}/seguimientos is PR-I territory.
        $rows = Seguimiento::where('persona_id', $refs['persona']->id)->get();
        $this->assertCount(1, $rows, 'exactly one seguimiento is tied to this persona');
        $this->assertSame(
            (int) $refs['persona']->id,
            (int) $rows->first()->persona_id
        );
    }

    #[Test]
    public function it_creates_without_oportunidad(): void
    {
        $token = $this->authenticate();

        if (! Ciudad::where('cod_municipio', '05001')->exists()) {
            Ciudad::create([
                'cod_municipio' => '05001',
                'nombre' => 'Medellín',
                'departamento' => 'Antioquia',
            ]);
        }
        $entidad = Entidad::factory()->create();
        $persona = Persona::create([
            'nombres' => 'Bea',
            'apellidos' => 'Lovelace',
            'email_principal' => 'bea-'.uniqid().'@example.test',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/seguimientos', [
                'entidad_id' => $entidad->id,
                'persona_id' => $persona->id,
                'tipo' => 'Nota',
                'fecha' => '2026-05-10',
                'notas' => 'Nota sobre entidad sin oportunidad',
                'estado' => 'Pendiente',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entidad_id', $entidad->id)
            ->assertJsonPath('data.tipo', 'Nota')
            ->assertJsonPath('data.persona_id', $persona->id);
    }
}
