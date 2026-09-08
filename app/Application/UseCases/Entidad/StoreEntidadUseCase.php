<?php

namespace App\Application\UseCases\Entidad;

use App\Domain\Entities\Entidad as EntidadEntity;
use App\Domain\Events\EntidadChanged;
use App\Domain\Repositories\EntidadRepositoryInterface;
use App\Infrastructure\Webhook\EntidadSnapshotBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Commit 7 — `StoreEntidadUseCase` dispatches `EntidadChanged(action='created')`
 * after the DB write commits. Mirrors `StorePersonaUseCase` (PR-J).
 *
 * The snapshot is built off the freshly-created row via
 * `EntidadSnapshotBuilder`, which is the same shape Mercurio's CQRS
 * mirror (`mercurio_entidades_snapshot`) consumes for the persona flow.
 *
 * The dispatch sits OUTSIDE the repository call — we want the event
 * fired only after the row is visible. The use case is synchronous
 * (the `BaseRepository::create` already wrote the row), so there's no
 * transaction to coordinate with. If a future refactor wraps the
 * `repository->create` in a `DB::transaction`, the dispatch should
 * move into `DB::afterCommit()` so we never emit a phantom event for
 * a rolled-back insert.
 */
class StoreEntidadUseCase
{
    public function __construct(
        private EntidadRepositoryInterface $repository,
        private EntidadSnapshotBuilder $snapshotBuilder,
    ) {}

    public function execute(array $data): mixed
    {
        $entidad = $this->repository->create($data);

        $this->dispatchCreated($entidad);

        return $entidad;
    }

    /**
     * Project the domain entity to the receiver-facing snapshot shape.
     * The builder takes the Eloquent model (re-hydrated from the
     * domain entity's id) so the principal-row lookups stay
     * centralized in `EntidadSnapshotBuilder`.
     */
    private function dispatchCreated(EntidadEntity $entidad): void
    {
        $snapshot = $this->snapshotBuilder->buildForEntidadId((int) $entidad->id);

        if ($snapshot === null) {
            return;
        }

        event(new EntidadChanged(
            action: 'created',
            entidad_id: (int) $entidad->id,
            snapshot: $snapshot,
            occurred_at: Carbon::now()->toIso8601String(),
            event_id: (string) Str::uuid(),
        ));
    }
}