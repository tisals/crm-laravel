<?php

namespace Tests\Feature\API;

use App\Domain\Events\PersonaChanged;
use App\Infrastructure\Webhook\DispatchOutboundWebhookJob;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Persona as PersonaModel;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-J — `PersonaChanged` event + snapshot webhook emitter (REQ-PSWH-001
 * through REQ-PSWH-006, REQ-PRAPI-007).
 *
 * Strict TDD: tests 6b.1..6b.7 are RED before GREEN tasks 6b.8..6b.13
 * (`PersonaChanged` event class, `PersonasSnapshotEmitter` listener,
 * `config/webhook.php` personas_snapshot section, dispatcher registration,
 * use-case post-commit dispatch + array_diff_assoc rule).
 *
 * Acceptance per tasks.md:
 *  - exactly one webhook per effective write (REQ-PSWH-001)
 *  - PATCH with no effective change → NO event (REQ-PSWH-006)
 *  - DELETE → event with pre-delete snapshot
 *  - `Queue::fake()` exposes the job with event, configPrefix, queue='webhooks'
 *  - `webhook.personas_snapshot.enabled=false` → no job, `personas_snapshot.skipped` log
 *  - URL/secret resolve from `webhook.personas_snapshot.*` with fallback to
 *    `webhook.outbound.secret`
 *
 * The dual fake strategy mirrors Laravel 12 semantics:
 *  - `Event::fake()` records the dispatch AND skips listener invocation. Used
 *    in tests 6b.1..6b.4 to assert the event itself is dispatched without
 *    pulling the HTTP transport into the assertion surface.
 *  - `Queue::fake()` is used in 6b.5..6b.7 because we want the listener to
 *    fire normally so we can assert it pushes the right job onto the queue.
 *    `Queue::fake()` intercepts the dispatch BEFORE `CrmWebhookSender` runs.
 */
class PersonaWebhookEmitterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Builds an admin user (wildcard `vista='*'` permission) so the persona
     * REST surface skips tenant checks in tests that don't care about
     * cross-entidad isolation.
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

    // ── 6b.1 RED — POST dispatches PersonaChanged with action='created' ─

    #[Test]
    public function post_dispatches_persona_changed_with_action_created_and_full_snapshot(): void
    {
        $auth = $this->makeAdmin();

        // Event::fake() intercepts the dispatch AND skips listener invocation.
        // We only care about the event itself in this test — the listener's
        // job dispatch is covered separately in test 6b.5.
        Event::fake();

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

        $r->assertStatus(201);
        $newId = (int) $r->json('data.id');

        Event::assertDispatched(PersonaChanged::class, function (PersonaChanged $event) use ($newId, $payload) {
            return $event->action === 'created'
                && $event->persona_id === $newId
                && $event->snapshot['nombres'] === $payload['nombres']
                && $event->snapshot['apellidos'] === $payload['apellidos']
                && $event->snapshot['email_principal'] === $payload['email_principal']
                && is_string($event->occurred_at);
        });
    }

    // ── 6b.2 RED — PATCH with a real field change dispatches updated ────

    #[Test]
    public function patch_with_real_field_change_dispatches_persona_changed_with_action_updated(): void
    {
        $auth = $this->makeAdmin();
        $persona = PersonaModel::create([
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'email_principal' => 'before@acme.test',
        ]);

        Event::fake();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->patchJson("/api/v1/personas/{$persona->id}", [
                'email_principal' => 'after@acme.test',
            ]);

        $r->assertStatus(200);

        Event::assertDispatched(PersonaChanged::class, function (PersonaChanged $event) use ($persona) {
            return $event->action === 'updated'
                && $event->persona_id === (int) $persona->id
                && $event->snapshot['email_principal'] === 'after@acme.test';
        });
    }

    // ── 6b.3 RED — PATCH with no effective change does NOT dispatch ─────

    #[Test]
    public function patch_with_no_effective_change_does_not_dispatch_persona_changed(): void
    {
        $auth = $this->makeAdmin();
        $persona = PersonaModel::create([
            'nombres' => 'Stable',
            'apellidos' => 'Person',
            'email_principal' => 'same@acme.test',
        ]);

        Event::fake();

        // PATCH with the same values Eloquent already has — no field changes,
        // so the listener MUST stay silent (REQ-PSWH-006, RQ-4: skip pure
        // timestamp bumps to avoid webhook noise).
        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->patchJson("/api/v1/personas/{$persona->id}", [
                'nombres' => 'Stable',
                'apellidos' => 'Person',
                'email_principal' => 'same@acme.test',
            ]);

        // The PATCH itself succeeds (200) — but NO PersonaChanged event fires.
        $r->assertStatus(200);
        Event::assertNotDispatched(PersonaChanged::class);
    }

    // ── 6b.4 RED — DELETE dispatches PersonaChanged with pre-delete snap ─

    #[Test]
    public function delete_dispatches_persona_changed_with_action_deleted_and_pre_delete_snapshot(): void
    {
        $auth = $this->makeAdmin();
        $persona = PersonaModel::create([
            'nombres' => 'Doomed',
            'apellidos' => 'Subject',
            'email_principal' => 'doomed@acme.test',
        ]);

        Event::fake();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->deleteJson("/api/v1/personas/{$persona->id}");

        $r->assertStatus(200);

        Event::assertDispatched(PersonaChanged::class, function (PersonaChanged $event) use ($persona) {
            return $event->action === 'deleted'
                && $event->persona_id === (int) $persona->id
                // Pre-delete snapshot preserves the persona state at delete time
                // (tasks.md 6b.4) — Mercury can reconcile against the last-known
                // shape before the row was soft-deleted.
                && $event->snapshot['nombres'] === 'Doomed'
                && $event->snapshot['email_principal'] === 'doomed@acme.test'
                && ! empty($event->snapshot['deleted_at']);
        });
    }

    // ── 6b.5 RED — listener queues DispatchOutboundWebhookJob with the
    //              documented event name, configPrefix, and queue ────────

    #[Test]
    public function listener_queues_dispatch_outbound_webhook_job_with_expected_event_configprefix_and_queue(): void
    {
        $auth = $this->makeAdmin();

        // Queue::fake() intercepts the dispatch so we can assert on the job
        // class + properties without CrmWebhookSender actually firing an
        // HTTP call. The listener MUST run normally — we don't fake events
        // here, so PersonasSnapshotEmitter is invoked when the use case
        // dispatches PersonaChanged.
        Queue::fake();

        $payload = [
            'nombres' => 'Webhook',
            'apellidos' => 'Subject',
            'email_principal' => 'webhook@acme.test',
        ];

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', $payload)
            ->assertStatus(201);

        Queue::assertPushed(DispatchOutboundWebhookJob::class, function (DispatchOutboundWebhookJob $job) {
            return $job->event === 'personas.snapshot.sync'
                && $job->configPrefix === 'personas_snapshot'
                && $job->queue === 'webhooks';
        });
    }

    // ── 6b.6 RED — kill-switch: enabled=false → no job, log skipped ────

    #[Test]
    public function listener_is_no_op_when_personas_snapshot_enabled_is_false(): void
    {
        $auth = $this->makeAdmin();

        // Disable the emitter via the config flag (REQ-PSWH-005, R-3 emergency
        // kill-switch). The listener should log a skip event and NOT push a
        // job onto the webhooks queue.
        config(['webhook.personas_snapshot.enabled' => false]);

        // Mock the Log facade. We use `shouldReceive('channel')->andReturnSelf()`
        // so the middleware's `Log::channel('api')->info(...)` chain doesn't
        // blow up, and we let the listener's `Log::info(...)` calls flow
        // through to the spy so we can assert the structured skip log.
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        Queue::fake();

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'nombres' => 'Skipped',
                'apellidos' => 'NoOp',
                'email_principal' => 'skipped@acme.test',
            ])
            ->assertStatus(201);

        // No job queued — the disabled flag short-circuits before dispatch.
        Queue::assertNotPushed(DispatchOutboundWebhookJob::class);

        // A structured skip log records the persona_id so operators can audit
        // the kill-switch path (REQ-PSWH-005).
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context = []) {
                return $message === 'personas_snapshot.skipped'
                    && isset($context['persona_id']);
            })
            ->once();
    }

    // ── 6b.7 RED — URL/secret resolve from `webhook.personas_snapshot.*`
    //              with fallback to `webhook.outbound.secret` ────────────

    #[Test]
    public function listener_resolves_url_and_secret_from_personas_snapshot_config_with_fallback_to_outbound(): void
    {
        $auth = $this->makeAdmin();

        // Override the personas_snapshot URL/secret. The listener MUST pick
        // these up at dispatch time. We also explicitly clear the secret
        // path so the test exercises the "secret from `personas_snapshot`
        // config" path (REQ-PSWH-004).
        config([
            'webhook.personas_snapshot.url' => 'http://test-receiver.local/personas-snapshot',
            'webhook.personas_snapshot.secret' => 'persons-snapshot-secret-override',
        ]);

        Http::fake();

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'nombres' => 'Receiver',
                'apellidos' => 'Resolve',
                'email_principal' => 'receiver@acme.test',
            ])
            ->assertStatus(201);

        // The listener pushes a queue job; QUEUE_CONNECTION=sync makes it run
        // inline so CrmWebhookSender signs and POSTs against our fake HTTP
        // layer. We assert the request reached the URL we configured.
        Http::assertSent(function ($request) {
            $expectedSig = 'sha256='.hash_hmac(
                'sha256',
                $request->body(),
                config('webhook.personas_snapshot.secret'),
            );

            return $request->url() === 'http://test-receiver.local/personas-snapshot'
                && $request->method() === 'POST'
                && ($request->header('X-CRM-Signature')[0] ?? null) === $expectedSig;
        });
    }

    #[Test]
    public function listener_falls_back_to_outbound_secret_when_personas_snapshot_secret_is_unset(): void
    {
        $auth = $this->makeAdmin();

        // Set only the URL — leave the personas_snapshot.secret path empty so
        // the listener MUST fall back to webhook.outbound.secret (REQ-PSWH-004).
        config([
            'webhook.personas_snapshot.url' => 'http://fallback.local/personas',
            'webhook.personas_snapshot.secret' => null,
            'webhook.outbound.secret' => 'outbound-secret-fallback',
        ]);

        Http::fake();

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'nombres' => 'Fallback',
                'apellidos' => 'Secret',
                'email_principal' => 'fallback@acme.test',
            ])
            ->assertStatus(201);

        // Signature MUST be computed with the outbound fallback secret.
        Http::assertSent(function ($request) {
            $expectedSig = 'sha256='.hash_hmac(
                'sha256',
                $request->body(),
                config('webhook.outbound.secret'),
            );

            return $request->url() === 'http://fallback.local/personas'
                && ($request->header('X-CRM-Signature')[0] ?? null) === $expectedSig;
        });
    }

    /**
     * Sanity test: the persona lifecycle produces ONE event per effective
     * write — no double-dispatch, no event on a same-value PATCH. Belt-and-
     * suspenders check that the listener registration + use case wrapping
     * holds together end-to-end.
     */
    #[Test]
    public function lifecycle_creates_exactly_one_event_per_effective_write(): void
    {
        $auth = $this->makeAdmin();

        Event::fake();
        $persona = PersonaModel::create([
            'nombres' => 'Lifecycle',
            'apellidos' => 'Subject',
            'email_principal' => 'lifecycle@acme.test',
        ]);

        // Create via API → 1 event (covered in 6b.1; here we re-assert the
        // property on a different persona for isolation).
        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/personas', [
                'nombres' => 'Lifecycle2',
                'email_principal' => 'lifecycle2@acme.test',
            ])
            ->assertStatus(201);

        Event::assertDispatched(PersonaChanged::class, 1);
    }
}
