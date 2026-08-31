<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Entities\Persona as PersonaEntity;
use App\Domain\Events\PersonaChanged;
use App\Domain\Repositories\PersonaRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * PR-J — StorePersonaUseCase dispatches `PersonaChanged(action='created')`
 * after the DB write commits (REQ-PSWH-001, AD-8, R-3).
 *
 * Why the dispatch sits OUTSIDE the `DB::transaction` callback (not via
 * `DB::afterCommit()`): the controller path is not nested inside another
 * transaction, so dispatching right after the closure returns gives the
 * same post-commit semantics without the `RefreshDatabase` interaction
 * quirk where `afterCommit` callbacks queue up until the outer test
 * transaction commits at tear-down. Keeping the dispatch at the use-case
 * boundary (post-transaction, pre-return) keeps tests deterministic and
 * matches the spec's "after the DB commit succeeds" wording.
 *
 * The repository is invoked through `DB::transaction(...)` so the create
 * and any future side-effects (e.g. PR-K's `Natural → entidad` inversion)
 * happen atomically. If the transaction rolls back, the event is NOT
 * fired — there is no half-state where Mercurio sees a write that
 * crm-laravel actually rejected.
 */
class StorePersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        $persona = DB::transaction(fn () => $this->repository->create($data));

        // Post-commit dispatch: the create succeeded, the row is visible.
        // The listener (PersonasSnapshotEmitter) is a no-op on failure —
        // webhook emission NEVER breaks the originating request (R-3).
        event(new PersonaChanged(
            action: 'created',
            persona_id: (int) $persona->id,
            snapshot: $this->toSnapshot($persona),
            occurred_at: now()->toIso8601String(),
        ));

        return $persona;
    }

    /**
     * Project the domain entity to the receiver-facing snapshot shape.
     * Re-uses `toArray()` so the wire format stays in lock-step with the
     * domain entity (the resource's relations block is intentionally NOT
     * included — that's an HTTP-only convenience; the webhook payload
     * must stay small and receiver-stable).
     */
    private function toSnapshot(PersonaEntity $persona): array
    {
        return $persona->toArray();
    }
}
