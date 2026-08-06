<?php

namespace App\Application\UseCases\Usuario;

use App\Application\Services\MultiAppRbacService;
use App\Domain\Repositories\UsuarioAppPermisoRepositoryInterface;
use App\Models\UsuarioAppPermiso;
use Illuminate\Support\Facades\Log;

/**
 * Grant a single scoped permission to a user for a specific app.
 *
 * Idempotent: granting a vista that the user already holds is a no-op
 * (we use `firstOrCreate` under the hood). After the mutation, the
 * affected user's snapshot is marked stale and the RBAC cache is purged
 * so the next read picks up the change.
 */
class GrantAppPermissionUseCase
{
    public function __construct(
        private UsuarioAppPermisoRepositoryInterface $repository,
        private MultiAppRbacService $rbacService,
    ) {}

    /**
     * @return array{id: int, usuario_id: int, app_id: int, vista: string}
     */
    public function execute(int $usuarioId, int $appId, string $vista): array
    {
        $vista = trim($vista);
        if ($vista === '') {
            throw new \InvalidArgumentException('vista must not be empty');
        }

        $existing = UsuarioAppPermiso::withTrashed()
            ->where('usuario_id', $usuarioId)
            ->where('app_id', $appId)
            ->where('vista', $vista)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $entity = $existing;
        } else {
            $created = $this->repository->create([
                'usuario_id' => $usuarioId,
                'app_id' => $appId,
                'vista' => $vista,
            ]);
            $entity = $created;
        }

        // Cache busting: drop the RBAC cache entry for this user/vista
        // and bump the user's identity snapshot into the stale state.
        $this->rbacService->invalidate($usuarioId, $appId, $vista);
        $this->rbacService->invalidateAllForUser($usuarioId);

        Log::info('rbac.permission.granted', [
            'usuario_id' => $usuarioId,
            'app_id' => $appId,
            'vista' => $vista,
        ]);

        return is_array($entity) ? $entity : $entity->toArray();
    }
}
