<?php

namespace App\Application\UseCases\Usuario;

use App\Application\Services\MultiAppRbacService;
use App\Models\UsuarioAppPermiso;

/**
 * Revoke a single scoped permission from a user for a specific app.
 *
 * Idempotent: revoking a vista the user does not hold is a no-op
 * (returns false but does not error). After the mutation, the affected
 * user's snapshot is marked stale and the RBAC cache is purged.
 */
class RevokeAppPermissionUseCase
{
    public function __construct(
        private MultiAppRbacService $rbacService,
    ) {}

    public function execute(int $usuarioId, int $appId, string $vista): bool
    {
        $vista = trim($vista);
        if ($vista === '') {
            throw new \InvalidArgumentException('vista must not be empty');
        }

        $row = UsuarioAppPermiso::where('usuario_id', $usuarioId)
            ->where('app_id', $appId)
            ->where('vista', $vista)
            ->first();

        if (! $row) {
            // Idempotent — already not granted. Don't touch the cache.
            return false;
        }

        $row->delete();

        $this->rbacService->invalidate($usuarioId, $appId, $vista);
        $this->rbacService->invalidateAllForUser($usuarioId);

        \Log::info('rbac.permission.revoked', [
            'usuario_id' => $usuarioId,
            'app_id' => $appId,
            'vista' => $vista,
        ]);

        return true;
    }
}
