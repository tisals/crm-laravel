<?php

namespace Tests\Feature\API;

use App\Domain\Events\EntidadChanged;
use App\Infrastructure\Webhook\DispatchOutboundWebhookJob;
use App\Models\EntidadRelacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesEntidadForTesting;
use Tests\TestCase;

/**
 * Commit 7 — `EntidadRelacionObserver` reactive emissions.
 *
 * Mirrors the contract documented in the observer:
 *  - `created` on a pivot row → emits `updated` snapshot
 *  - `updated` ONLY when `effective_to` changes → emits `updated` snapshot
 *  - `deleted` on a pivot row → emits `updated` snapshot
 *  - `effective_to` field is the marker Mercurio's `is_active` flag
 *    derives from
 *
 * Why we use `Event::fake([EntidadChanged::class])` (partial fake):
 *  - A blanket `Event::fake()` intercepts Eloquent's model lifecycle
 *    events (`eloquent.created`, etc.) BEFORE they reach the observer,
 *    so the observer never fires. The test would assert a phantom
 *    dispatch that can't happen because the observer path was cut off.
 *  - A partial `Event::fake([EntidadChanged::class])` only fakes
 *    `EntidadChanged` dispatches. Model lifecycle events still flow
 *    through the dispatcher normally → the observer fires → it
 *    dispatches `EntidadChanged` → that's captured.
 *
 * We also use `Queue::fake()` as a second safety net to assert the
 * listener side: every `EntidadChanged` should reach
 * `EntidadesSnapshotEmitter::handle()` which queues a
 * `DispatchOutboundWebhookJob`.
 */
class EntidadRelacionObserverTest extends TestCase
{
    use RefreshDatabase, CreatesEntidadForTesting;

    /**
     * Capture EntidadChanged dispatches while letting model lifecycle
     * events flow normally.
     *
     * @return \Illuminate\Support\Testing\Fakes\EventFake
     */
    private function fakeEvents(): \Illuminate\Support\Testing\Fakes\EventFake
    {
        // Partial fake — only EntidadChanged is intercepted. Model
        // events (eloquent.created/updated/deleted on EntidadRelacion)
        // still fire so the observer's hook runs.
        return Event::fake([EntidadChanged::class]);
    }

    #[Test]
    public function creating_a_pivot_row_emits_an_updated_snapshot_with_is_active_true(): void
    {
        // Pre-create an inactive entidad (no open pivot) so we can
        // observe the `is_active` flag flip to true after the create.
        $entidad = $this->makeInactiveEntidad('prospecto');

        $fake = $this->fakeEvents();

        EntidadRelacion::create([
            'entidad_id' => $entidad->id,
            'tipo_relacion' => 'cliente',
            'effective_from' => Carbon::now()->toDateString(),
            'effective_to' => null,
            'frecuencia' => 'unica',
            'recurrencia_cada_meses' => null,
            'vigencia_meses' => null,
        ]);

        $fake->assertDispatched(EntidadChanged::class, function (EntidadChanged $event) use ($entidad) {
            return $event->action === 'updated'
                && $event->entidad_id === (int) $entidad->id
                && $event->snapshot['is_active'] === true
                && $event->snapshot['relaciones_count'] === 2 // one closed + one open
                && ! empty($event->event_id);
        });
    }

    #[Test]
    public function updating_effective_to_on_a_pivot_row_emits_an_updated_snapshot(): void
    {
        $entidad = $this->makeEntidad('cliente'); // active via open pivot

        $pivot = EntidadRelacion::where('entidad_id', $entidad->id)->first();

        $fake = $this->fakeEvents();

        // Close the open pivot → `is_active` should flip to false.
        $pivot->update(['effective_to' => Carbon::now()->toDateString()]);

        $fake->assertDispatched(EntidadChanged::class, function (EntidadChanged $event) use ($entidad) {
            return $event->action === 'updated'
                && $event->entidad_id === (int) $entidad->id
                && $event->snapshot['is_active'] === false
                && ! empty($event->event_id);
        });
    }

    #[Test]
    public function updating_a_pivot_row_without_changing_effective_to_does_not_emit(): void
    {
        $entidad = $this->makeEntidad('cliente');
        $pivot = EntidadRelacion::where('entidad_id', $entidad->id)->first();

        $fake = $this->fakeEvents();

        // Update an unrelated field. `frecuencia` is ENUM('unica',
        // 'recurrente') and the CHECK constraint enforces
        // recurrencia_cada_meses IS NOT NULL when frecuencia='recurrente'
        // (migration 2026_09_03_140000). Picking a value outside the
        // 1..12 range avoids any recurrence mutation side-effects.
        $pivot->update([
            'frecuencia' => 'recurrente',
            'recurrencia_cada_meses' => 6,
            'vigencia_meses' => 12,
        ]);

        $fake->assertNotDispatched(EntidadChanged::class);
    }

    #[Test]
    public function deleting_a_pivot_row_emits_an_updated_snapshot(): void
    {
        $entidad = $this->makeEntidad('cliente');
        $pivot = EntidadRelacion::where('entidad_id', $entidad->id)->first();

        $fake = $this->fakeEvents();

        $pivot->delete();

        $fake->assertDispatched(EntidadChanged::class, function (EntidadChanged $event) use ($entidad) {
            return $event->action === 'updated'
                && $event->entidad_id === (int) $entidad->id
                && $event->snapshot['is_active'] === false
                && $event->snapshot['relaciones_count'] === 0
                && ! empty($event->event_id);
        });
    }

    #[Test]
    public function each_pivot_mutation_emits_a_fresh_uuid_event_id(): void
    {
        $entidad = $this->makeInactiveEntidad('prospecto');

        // Use Queue::fake() so the listener runs end-to-end. Each
        // listener invocation pushes a DispatchOutboundWebhookJob whose
        // `data` payload carries the EntidadChanged event with the
        // freshly minted `event_id`. We pull event_ids from those jobs.
        Queue::fake();

        EntidadRelacion::create([
            'entidad_id' => $entidad->id,
            'tipo_relacion' => 'cliente',
            'effective_from' => Carbon::now()->toDateString(),
            'effective_to' => null,
            'frecuencia' => 'unica',
            'recurrencia_cada_meses' => null,
            'vigencia_meses' => null,
        ]);

        $created = EntidadRelacion::where('entidad_id', $entidad->id)->first();
        $created->update(['effective_to' => Carbon::now()->toDateString()]);
        $created->delete();

        // Three pivot mutations → three events (and therefore three jobs).
        Queue::assertPushed(DispatchOutboundWebhookJob::class, 3);

        // Each job's `data` is the wire envelope the listener built.
        // The envelope is `{ event, timestamp, data: { event_id, ... } }`
        // per `EntidadesSnapshotEmitter::handle()`. We pull `event_id`
        // from each job and assert uniqueness across the three dispatches.
        $capturedIds = [];
        foreach (Queue::pushed(DispatchOutboundWebhookJob::class) as $job) {
            $capturedIds[] = $job->data['data']['event_id'] ?? null;
        }

        $this->assertCount(3, $capturedIds);
        $this->assertSame(3, count(array_unique($capturedIds)), 'event_ids must be unique across pivot mutations');
        // Every event_id is a non-empty UUIDv4-shaped string.
        foreach ($capturedIds as $id) {
            $this->assertNotEmpty($id);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
            );
        }
    }

    #[Test]
    public function raw_db_insert_into_entidad_relacion_does_not_emit_a_snapshot(): void
    {
        // Inversion path (StorePersonaUseCase + EntidadFactory) writes
        // the pivot via raw DB::table() — those writes MUST NOT trigger
        // the observer, otherwise the create flow would emit a duplicate
        // `EntidadChanged(updated)` on top of the use case's
        // `EntidadChanged(created)`.
        $entidad = $this->makeEntidad('cliente');

        $fake = $this->fakeEvents();

        \Illuminate\Support\Facades\DB::table('entidad_relacion')->insert([
            'entidad_id' => $entidad->id,
            'tipo_relacion' => 'propia',
            'effective_from' => Carbon::now()->toDateString(),
            'effective_to' => null,
            'frecuencia' => 'unica',
            'recurrencia_cada_meses' => null,
            'vigencia_meses' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Observer must NOT fire — the raw insert bypasses Eloquent
        // model events. The use case / factory is responsible for
        // emitting its own event if needed.
        $fake->assertNotDispatched(EntidadChanged::class);
    }
}