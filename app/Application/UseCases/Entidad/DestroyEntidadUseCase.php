<?php

namespace App\Application\UseCases\Entidad;

use App\Domain\Entities\Entidad as EntidadEntity;
use App\Domain\Events\EntidadChanged;
use App\Domain\Repositories\EntidadRepositoryInterface;
use App\Infrastructure\Webhook\EntidadSnapshotBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Commit 7 — `DestroyEntidadUseCase` dispatches
 * `EntidadChanged(action='deleted')` with the PRE-DELETE snapshot
 * (tasks.md 6b.4 mirror, AD-8).
 *
 * The pre-delete snapshot is captured BEFORE the repository's `delete()`
 * call so the receiver sees the entity in its last-known state, not the
 * post-delete soft-delete tombstone. We also stamp `deleted_at` onto
 * the snapshot so Mercurio can mark the mirror row as deleted without
 * re-querying.
 *
 * The actual delete is soft (Eloquent's `SoftDeletes` trait on the
 * `Entidad` model) — the row stays in `entidad` with `deleted_at`
 * set. That matches the persona destroy behavior (PR-J) and keeps
 * referential integrity for `oportunidad`, `entidad_persona`, etc.
 */
class DestroyEntidadUseCase
{
    public function __construct(
        private EntidadRepositoryInterface $repository,
        private EntidadSnapshotBuilder $snapshotBuilder,
    ) {}

    public function execute(int $id): bool
    {
        $existing = $this->repository->findById($id);

        if (! $existing) {
            return false;
        }

        // Capture pre-delete snapshot BEFORE the write so receivers see
        // the entity's last-known shape. We stamp `deleted_at` onto the
        // snapshot so Mercurio knows when the row was tombstoned.
        $this->dispatchDeleted($existing);

        return $this->repository->delete($id);
    }

    private function dispatchDeleted(EntidadEntity $entidad): void
    {
        $snapshot = $this->snapshotBuilder->buildForEntidadId((int) $entidad->id);

        if ($snapshot === null) {
            return;
        }

        // Stamp `deleted_at` so the receiver knows the row was soft-
        // deleted at this point in time — even if Eloquent's `delete()`
        // moves the actual stamp slightly forward.
        $snapshot['deleted_at'] = Carbon::now()->toIso8601String();

        event(new EntidadChanged(
            action: 'deleted',
            entidad_id: (int) $entidad->id,
            snapshot: $snapshot,
            occurred_at: Carbon::now()->toIso8601String(),
            event_id: (string) Str::uuid(),
        ));
    }
}