<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Events\PersonaChanged;
use App\Domain\Repositories\PersonaRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * PR-J — DestroyPersonaUseCase dispatches `PersonaChanged(action='deleted')`
 * with the PRE-DELETE snapshot (tasks.md 6b.4, REQ-PSWH-001, AD-8).
 *
 * The pre-delete snapshot is captured BEFORE the `DB::transaction` so the
 * receiver sees the persona in its last-known state, not the post-delete
 * soft-delete tombstone. We also stamp `deleted_at` onto the snapshot so
 * Mercurio can mark the mirror row as deleted without re-querying.
 *
 * The actual delete is soft (Eloquent's `SoftDeletes` trait on the
 * Persona model) — the row stays in `personas` with `deleted_at` set.
 * That matches existing contacto destroy behaviour elsewhere in the app
 * (OI-8: contacts retain history after delete).
 */
class DestroyPersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        $existing = $this->repository->findById($id);

        if (! $existing) {
            return false;
        }

        // Capture pre-delete snapshot BEFORE the write so receivers see
        // the persona's last-known shape (tasks.md 6b.4). We also stamp
        // `deleted_at` onto the snapshot so Mercurio knows when the row
        // was tombstoned.
        $snapshot = $existing->toArray();
        $snapshot['deleted_at'] = now()->toIso8601String();

        $deleted = DB::transaction(fn () => $this->repository->delete($id));

        if (! $deleted) {
            return false;
        }

        event(new PersonaChanged(
            action: 'deleted',
            persona_id: (int) $id,
            snapshot: $snapshot,
            occurred_at: now()->toIso8601String(),
        ));

        return true;
    }
}
