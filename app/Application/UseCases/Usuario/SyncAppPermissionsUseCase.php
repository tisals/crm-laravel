<?php

namespace App\Application\UseCases\Usuario;

use App\Application\Services\MultiAppRbacService;
use App\Domain\Repositories\UsuarioAppPermisoRepositoryInterface;

/**
 * Replace ALL scoped permissions for a (user, app) in one atomic call.
 *
 * Used by the admin UI's "sync" operation: the payload contains the
 * full desired set; rows not in the payload are removed, rows in the
 * payload are inserted. Wrapped in a DB transaction inside the
 * repository, so a partial failure won't leave the user in a half-synced
 * state.
 */
class SyncAppPermissionsUseCase
{
    public function __construct(
        private UsuarioAppPermisoRepositoryInterface $repository,
        private MultiAppRbacService $rbacService,
    ) {}

    /**
     * @param  array<string>  $vistas
     * @return array<array{id:int,usuario_id:int,app_id:int,vista:string}>
     */
    public function execute(int $usuarioId, int $appId, array $vistas): array
    {
        $synced = $this->repository->sync($usuarioId, $appId, $vistas);

        // Sync invalidates the entire user's RBAC cache (we don't know
        // which vistas were removed vs kept).
        $this->rbacService->invalidateAllForUser($usuarioId);

        \Log::info('rbac.permission.synced', [
            'usuario_id' => $usuarioId,
            'app_id' => $appId,
            'count' => count($synced),
        ]);

        return array_map(fn ($p) => $p->toArray(), $synced);
    }
}
