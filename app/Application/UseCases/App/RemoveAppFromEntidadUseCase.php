<?php

namespace App\Application\UseCases\App;

use App\Application\UseCases\Me\RefreshUserIdentitySnapshotUseCase;
use App\Domain\Repositories\UsuarioAppPermisoRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RemoveAppFromEntidadUseCase
{
    public function __construct(
        private UsuarioAppPermisoRepositoryInterface $permisoRepository,
        private RefreshUserIdentitySnapshotUseCase $refreshIdentitySnapshot,
    ) {}

    /**
     * Removes the app↔entidad assignment. Idempotent: returns true even
     * if the assignment doesn't exist (no error).
     *
     * Cascade (the multi-app behavior):
     *   After the pivot is removed, delete any `usuario_app_permisos`
     *   rows for (app_id, user) where user belongs to the entity via
     *   `entidad_usuario`. This way the affected users immediately lose
     *   the app-scoped overrides. Core rol permissions are unaffected.
     *
     *   Affected users' identity snapshots are marked stale so the next
     *   /me/identity read recomputes.
     */
    public function execute(int $appId, int $entidadId): bool
    {
        $deleted = DB::table('app_entidad')
            ->where('app_id', $appId)
            ->where('entidad_id', $entidadId)
            ->delete();

        // Even when the pivot didn't exist, we run the cascade — it
        // doubles as a "clean up stale per-user rows" operation if the
        // pivot was deleted manually upstream. Idempotent.
        $this->cascade($appId, $entidadId);

        return $deleted > 0;
    }

    /**
     * For every user on the entity, delete their scoped grants for the
     * given app. Then mark their snapshot row stale.
     */
    private function cascade(int $appId, int $entidadId): void
    {
        $userIds = DB::table('entidad_usuario')
            ->where('entidad_id', $entidadId)
            ->pluck('usuario_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($userIds === []) {
            return;
        }

        $removedTotal = 0;
        foreach ($userIds as $uid) {
            $removedTotal += $this->permisoRepository->deleteByUserAndApp($uid, $appId);
        }

        Log::info('rbac.cascade.removed', [
            'app_id' => $appId,
            'users' => count($userIds),
            'rows_removed' => $removedTotal,
        ]);

        $this->refreshIdentitySnapshot->invalidate($userIds);
    }
}
