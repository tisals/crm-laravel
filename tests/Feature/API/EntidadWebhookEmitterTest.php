<?php

namespace Tests\Feature\API;

use App\Domain\Events\EntidadChanged;
use App\Infrastructure\Webhook\DispatchOutboundWebhookJob;
use App\Models\Entidad;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesEntidadForTesting;
use Tests\TestCase;

/**
 * Commit 7 — `EntidadChanged` event + snapshot webhook emitter.
 *
 * Mirrors the structure of `PersonaWebhookEmitterTest` (PR-J):
 *  - exactly one webhook per effective write
 *  - PATCH with no effective change → NO event
 *  - DELETE → event with pre-delete snapshot (and `deleted_at` stamped)
 *  - `Queue::fake()` exposes the job with event, configPrefix, queue='webhooks'
 *  - `webhook.entidades_snapshot.enabled=false` → no job, log skipped
 *  - URL/secret resolve from `webhook.entidades_snapshot.*` with fallback
 *    to `webhook.outbound.secret`
 *
 * The dual fake strategy mirrors Laravel 12 semantics:
 *  - `Event::fake()` records the dispatch AND skips listener invocation.
 *    Used to assert on the event itself.
 *  - `Queue::fake()` is used to assert the listener pushes the right job
 *    onto the queue.
 */
class EntidadWebhookEmitterTest extends TestCase
{
    use RefreshDatabase, CreatesEntidadForTesting;

    /**
     * Builds an admin user (wildcard `vista='*'` permission) so the
     * entidad REST surface skips tenant checks in tests that don't care
     * about cross-entidad isolation.
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

    /**
     * Seed the principal contact rows that `EntidadSnapshotBuilder`
     * reads from. Mirrors the `EntidadResource::principalX()` semantics.
     */
    private function seedContactRows(int $entidadId, string $email, string $telefono, string $direccion, string $dominio): void
    {
        $now = now();

        DB::table('emails')->insert([
            'entidad_id' => $entidadId,
            'email' => $email,
            'tipo' => 'trabajo',
            'es_principal' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('telefonos')->insert([
            'entidad_id' => $entidadId,
            'numero' => $telefono,
            'tipo' => 'trabajo',
            'es_principal' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('direcciones')->insert([
            'entidad_id' => $entidadId,
            'direccion_principal' => $direccion,
            'tipo' => 'oficina',
            'es_principal' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('presencia_online')->insert([
            'entidad_id' => $entidadId,
            'tipo' => 'web',
            'plataforma' => 'otro',
            'url' => $dominio,
            'es_principal' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ── Event payload: created ─────────────────────────────────────────

    #[Test]
    public function post_dispatches_entidad_changed_with_action_created_and_full_snapshot(): void
    {
        $auth = $this->makeAdmin();

        Event::fake();

        $payload = [
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => '900123456-'.uniqid(),
            'nombre' => 'Acme Snapshot Test',
            'nombre_comercial' => 'Acme S.A.',
        ];

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad', $payload);

        $r->assertStatus(201);
        $newId = (int) $r->json('data.id');

        Event::assertDispatched(EntidadChanged::class, function (EntidadChanged $event) use ($newId, $payload) {
            return $event->action === 'created'
                && $event->entidad_id === $newId
                && $event->snapshot['nombre'] === $payload['nombre']
                && $event->snapshot['nombre_comercial'] === $payload['nombre_comercial']
                && $event->snapshot['tipo_persona'] === $payload['tipo_persona']
                && $event->snapshot['identificacion'] === $payload['identificacion']
                && $event->snapshot['is_active'] === false // no pivot yet at create time
                && $event->snapshot['relaciones_count'] === 0
                && $event->snapshot['contactos_count'] === 0
                && $event->snapshot['oportunidades_count'] === 0
                && $event->snapshot['usuarios_count'] === 0
                && ! empty($event->event_id)
                && is_string($event->occurred_at);
        });
    }

    // ── Event payload: updated (real change) ───────────────────────────

    #[Test]
    public function patch_with_real_field_change_dispatches_entidad_changed_with_action_updated(): void
    {
        $auth = $this->makeAdmin();
        $entidad = $this->makeEntidad('cliente');

        Event::fake();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->putJson("/api/v1/entidad/{$entidad->id}", [
                'tipo_persona' => 'Juridica',
                'nombre' => 'Acme Renamed',
            ]);

        $r->assertStatus(200);

        Event::assertDispatched(EntidadChanged::class, function (EntidadChanged $event) use ($entidad) {
            return $event->action === 'updated'
                && $event->entidad_id === (int) $entidad->id
                && $event->snapshot['nombre'] === 'Acme Renamed'
                && $event->snapshot['is_active'] === true
                && $event->snapshot['relaciones_count'] === 1
                && ! empty($event->event_id);
        });
    }

    // ── Event payload: updated (no effective change → no event) ───────

    #[Test]
    public function patch_with_no_effective_change_does_not_dispatch_entidad_changed(): void
    {
        $auth = $this->makeAdmin();
        $entidad = $this->makeEntidad('cliente');

        Event::fake();

        // PATCH with the SAME values Eloquent already has — no field
        // changes, so the listener MUST stay silent (mirrors REQ-PSWH-006).
        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->putJson("/api/v1/entidad/{$entidad->id}", [
                'tipo_persona' => 'Juridica',
                'nombre' => $entidad->nombre,
            ]);

        $r->assertStatus(200);
        Event::assertNotDispatched(EntidadChanged::class);
    }

    // ── Event payload: deleted (pre-delete snapshot + deleted_at) ──────

    #[Test]
    public function delete_dispatches_entidad_changed_with_action_deleted_and_pre_delete_snapshot(): void
    {
        $auth = $this->makeAdmin();
        $entidad = $this->makeEntidad('cliente');
        // Seed principal contact rows so the snapshot has them.
        $this->seedContactRows(
            (int) $entidad->id,
            'contact@acme.test',
            '+57-1-555-1234',
            'Calle 100 #15-20',
            'acme.test',
        );

        Event::fake();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->deleteJson("/api/v1/entidad/{$entidad->id}");

        $r->assertStatus(200);

        Event::assertDispatched(EntidadChanged::class, function (EntidadChanged $event) use ($entidad) {
            return $event->action === 'deleted'
                && $event->entidad_id === (int) $entidad->id
                && $event->snapshot['nombre'] === $entidad->nombre
                && $event->snapshot['email_principal'] === 'contact@acme.test'
                && $event->snapshot['telefono_principal'] === '+57-1-555-1234'
                && $event->snapshot['direccion_principal'] === 'Calle 100 #15-20'
                && $event->snapshot['dominio'] === 'acme.test'
                && $event->snapshot['is_active'] === true
                && ! empty($event->snapshot['deleted_at']);
        });
    }

    // ── Listener wiring: queue job + configPrefix ─────────────────────

    #[Test]
    public function listener_queues_dispatch_outbound_webhook_job_with_expected_event_configprefix_and_queue(): void
    {
        $auth = $this->makeAdmin();

        Queue::fake();

        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad', [
                'tipo_persona' => 'Juridica',
                'nombre' => 'Webhook Job Test',
                'identificacion' => '900987654-'.uniqid(),
            ]);

        $r->assertStatus(201);

        Queue::assertPushed(DispatchOutboundWebhookJob::class, function (DispatchOutboundWebhookJob $job) {
            return $job->event === 'entidades.snapshot.sync'
                && $job->configPrefix === 'entidades_snapshot'
                && $job->queue === 'webhooks';
        });
    }

    // ── Kill-switch ────────────────────────────────────────────────────

    #[Test]
    public function listener_is_no_op_when_entidades_snapshot_enabled_is_false(): void
    {
        $auth = $this->makeAdmin();

        // Disable the emitter via the config flag.
        config(['webhook.entidades_snapshot.enabled' => false]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        Queue::fake();

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad', [
                'tipo_persona' => 'Juridica',
                'nombre' => 'Skipped NoOp',
                'identificacion' => '900111222-'.uniqid(),
            ])
            ->assertStatus(201);

        Queue::assertNotPushed(DispatchOutboundWebhookJob::class);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context = []) {
                return $message === 'entidades_snapshot.skipped'
                    && isset($context['entidad_id']);
            })
            ->once();
    }

    // ── URL/secret resolution + fallback to outbound ──────────────────

    #[Test]
    public function listener_resolves_url_and_secret_from_entidades_snapshot_config_with_fallback_to_outbound(): void
    {
        $auth = $this->makeAdmin();

        // Override the entidades_snapshot URL/secret — the listener MUST
        // pick these up at dispatch time. We leave the secret empty so
        // the test exercises the "secret from `entidades_snapshot`
        // config" path.
        config([
            'webhook.entidades_snapshot.url' => 'http://test-receiver.local/entidades-snapshot',
            'webhook.entidades_snapshot.secret' => 'entidades-snapshot-secret-override',
        ]);

        Http::fake();

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad', [
                'tipo_persona' => 'Juridica',
                'nombre' => 'Receiver Resolve',
                'identificacion' => '900333444-'.uniqid(),
            ])
            ->assertStatus(201);

        Http::assertSent(function ($request) {
            $expectedSig = 'sha256='.hash_hmac(
                'sha256',
                $request->body(),
                config('webhook.entidades_snapshot.secret'),
            );

            return $request->url() === 'http://test-receiver.local/entidades-snapshot'
                && $request->method() === 'POST'
                && ($request->header('X-CRM-Signature')[0] ?? null) === $expectedSig;
        });
    }

    #[Test]
    public function listener_falls_back_to_outbound_secret_when_entidades_snapshot_secret_is_unset(): void
    {
        $auth = $this->makeAdmin();

        config([
            'webhook.entidades_snapshot.url' => 'http://fallback.local/entidades',
            'webhook.entidades_snapshot.secret' => null,
            'webhook.outbound.secret' => 'outbound-secret-fallback-entidades',
        ]);

        Http::fake();

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad', [
                'tipo_persona' => 'Juridica',
                'nombre' => 'Fallback Secret',
                'identificacion' => '900555666-'.uniqid(),
            ])
            ->assertStatus(201);

        Http::assertSent(function ($request) {
            $expectedSig = 'sha256='.hash_hmac(
                'sha256',
                $request->body(),
                config('webhook.outbound.secret'),
            );

            return $request->url() === 'http://fallback.local/entidades'
                && ($request->header('X-CRM-Signature')[0] ?? null) === $expectedSig;
        });
    }

    // ── Wire-shape contract: payload delivered to Mercurio ─────────────

    #[Test]
    public function wire_envelope_carries_uuid_event_id_for_mercurio_replay_dedup(): void
    {
        $auth = $this->makeAdmin();

        config([
            'webhook.entidades_snapshot.url' => 'http://dedup.local/entidades',
            'webhook.entidades_snapshot.secret' => 'dedup-secret',
        ]);

        Http::fake();

        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad', [
                'tipo_persona' => 'Juridica',
                'nombre' => 'Dedup Wire Test',
                'identificacion' => '900777888-'.uniqid(),
            ])
            ->assertStatus(201);

        // The body MUST contain event='entidades.snapshot.sync' and the
        // data block MUST carry a UUIDv4 `event_id` for Mercurio's
        // replay dedup.
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['event'] === 'entidades.snapshot.sync'
                && isset($body['data']['event_id'])
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['data']['event_id']) === 1
                && $body['data']['action'] === 'created'
                && isset($body['data']['snapshot']['id'])
                && $body['data']['snapshot']['is_active'] === false
                && $body['data']['snapshot']['relaciones_count'] === 0;
        });
    }

    // ── Idempotency: exactly one event per effective write ─────────────

    #[Test]
    public function lifecycle_creates_exactly_one_event_per_effective_write(): void
    {
        $auth = $this->makeAdmin();

        Event::fake();

        // Create via API → 1 event
        $r = $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->postJson('/api/v1/entidad', [
                'tipo_persona' => 'Juridica',
                'nombre' => 'Lifecycle Entidad',
                'identificacion' => '900999000-'.uniqid(),
            ]);

        $r->assertStatus(201);

        // PATCH with no change → still 1 event total (the create)
        $newId = (int) $r->json('data.id');
        $this->withHeader('Authorization', 'Bearer '.$auth['token'])
            ->putJson("/api/v1/entidad/{$newId}", [
                'tipo_persona' => 'Juridica',
                'nombre' => 'Lifecycle Entidad',
            ])
            ->assertStatus(200);

        Event::assertDispatched(EntidadChanged::class, 1);
    }
}