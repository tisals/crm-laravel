<?php

namespace App\Observers;

use App\Domain\Events\EntidadChanged;
use App\Infrastructure\Webhook\EntidadSnapshotBuilder;
use App\Models\EntidadRelacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Commit 7 — Observer on `EntidadRelacion` that emits an
 * `EntidadChanged(action='updated')` event whenever the pivot mutates.
 *
 * Why an observer (not an explicit dispatch in the use cases):
 *  - The pivot is the source of truth for `is_active`. Mercurio's CQRS
 *    mirror has to know when the flag flips — that's the whole point of
 *    dropping `entidad.estado` in Commit 5.5.
 *  - The pivot is mutated through many code paths (Eloquent
 *    `$model->relaciones()->save()`, `attach`, `detach`, raw
 *    `DB::table('entidad_relacion')`, factories, seeders). An observer
 *    gives us ONE place to react without sprinkling event dispatches.
 *
 * Why we DON'T fire for raw `DB::table()->insert()`:
 *  - `StorePersonaUseCase::createEntidadForNaturalPersona()` writes the
 *    inversion pivot via raw SQL so the observer stays silent there.
 *    The use case still emits its own `EntidadChanged(action='created')`
 *    after the inversion — the observer's missing fire on the raw write
 *    is intentional (the inversion already produced one event).
 *  - `EntidadFactory::configure()` does the same to keep tests fast.
 *  - If a future code path mutates the pivot via raw SQL AND needs a
 *    snapshot, that path should dispatch `EntidadChanged` explicitly.
 *
 * Idempotency:
 *  - Every dispatched event carries a UUIDv4 `event_id` so Mercurio can
 *    dedupe replays of the same pivot mutation (e.g. when a bulk
 *    import script writes 50 pivot rows and Mercurio replays them all).
 *
 * R-3 contract: the observer NEVER throws. Any failure inside the
 * snapshot builder is swallowed by the listener; the originating REST
 * write succeeds regardless.
 */
class EntidadRelacionObserver
{
    public function __construct(
        private EntidadSnapshotBuilder $builder,
    ) {}

    public function created(EntidadRelacion $relacion): void
    {
        $this->emit('updated', (int) $relacion->entidad_id);
    }

    public function updated(EntidadRelacion $relacion): void
    {
        // Only emit when the row's `effective_to` (or any other field
        // that changes `is_active`) actually changed — otherwise we'd
        // fire on every `created_by`/`updated_by` audit-stamp update.
        // The pivot's `effective_to` is the flag Mercurio cares about.
        if (! $relacion->wasChanged('effective_to')) {
            return;
        }

        $this->emit('updated', (int) $relacion->entidad_id);
    }

    public function deleted(EntidadRelacion $relacion): void
    {
        $this->emit('updated', (int) $relacion->entidad_id);
    }

    private function emit(string $action, int $entidadId): void
    {
        $snapshot = $this->builder->buildForEntidadId($entidadId);

        // The entity may have been hard-deleted between the pivot
        // mutation and our read (race condition during bulk imports).
        // Silently no-op — there's no snapshot to send.
        if ($snapshot === null) {
            return;
        }

        Event::dispatch(new EntidadChanged(
            action: $action,
            entidad_id: $entidadId,
            snapshot: $snapshot,
            occurred_at: Carbon::now()->toIso8601String(),
            event_id: (string) Str::uuid(),
        ));
    }
}