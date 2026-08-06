<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Usuario\GrantAppPermissionUseCase;
use App\Application\UseCases\Usuario\RevokeAppPermissionUseCase;
use App\Application\UseCases\Usuario\SyncAppPermissionsUseCase;
use App\Domain\Repositories\UsuarioAppPermisoRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin endpoints for managing per-(user, app) scoped permissions.
 *
 * Routes (see Modules/CRM/routes/api.php):
 *   GET    /api/v1/usuarios/{userId}/apps/{appId}/permisos
 *   POST   /api/v1/usuarios/{userId}/apps/{appId}/permisos          (sync)
 *   POST   /api/v1/usuarios/{userId}/apps/{appId}/permisos/grant
 *   DELETE /api/v1/usuarios/{userId}/apps/{appId}/permisos/{vista}
 *   POST   /api/v1/usuarios/{userId}/apps/{appId}/permisos/reset-to-role-defaults
 *
 * All endpoints are wrapped by `auth:sanctum` + `rbac` middleware; the
 * RBAC middleware checks the `usuarios.apps.permisos.*` vista against
 * the user's effective permissions for that specific app.
 */
class UsuarioPermisoController extends Controller
{
    public function __construct(
        private UsuarioAppPermisoRepositoryInterface $repository,
        private GrantAppPermissionUseCase $grantUseCase,
        private RevokeAppPermissionUseCase $revokeUseCase,
        private SyncAppPermissionsUseCase $syncUseCase,
    ) {}

    /**
     * GET /api/v1/usuarios/{userId}/apps/{appId}/permisos
     */
    public function index(int $userId, int $appId): JsonResponse
    {
        $permisos = $this->repository->findByUserAndApp($userId, $appId);

        return response()->json([
            'success' => true,
            'data' => [
                'usuario_id' => $userId,
                'app_id' => $appId,
                'permisos' => array_map(
                    fn ($p) => $p->vista,
                    $permisos
                ),
                'total' => count($permisos),
            ],
        ]);
    }

    /**
     * POST /api/v1/usuarios/{userId}/apps/{appId}/permisos
     * Replace-all. Body: { "vistas": ["contacto.update", ...] }
     */
    public function sync(Request $request, int $userId, int $appId): JsonResponse
    {
        $validated = $request->validate([
            'vistas' => 'required|array',
            'vistas.*' => 'string|max:100',
        ]);

        $synced = $this->syncUseCase->execute($userId, $appId, $validated['vistas']);

        return response()->json([
            'success' => true,
            'data' => [
                'usuario_id' => $userId,
                'app_id' => $appId,
                'permisos' => $synced,
                'total' => count($synced),
            ],
            'message' => 'Permisos sincronizados.',
        ]);
    }

    /**
     * POST /api/v1/usuarios/{userId}/apps/{appId}/permisos/grant
     * Body: { "vista": "contacto.update" }
     */
    public function grant(Request $request, int $userId, int $appId): JsonResponse
    {
        $validated = $request->validate([
            'vista' => 'required|string|max:100',
        ]);

        $result = $this->grantUseCase->execute($userId, $appId, $validated['vista']);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => "Permiso '{$validated['vista']}' otorgado.",
        ]);
    }

    /**
     * DELETE /api/v1/usuarios/{userId}/apps/{appId}/permisos/{vista}
     */
    public function destroy(int $userId, int $appId, string $vista): JsonResponse
    {
        $deleted = $this->revokeUseCase->execute($userId, $appId, $vista);

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'error' => "Permiso '{$vista}' no estaba asignado al usuario.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => "Permiso '{$vista}' revocado.",
        ]);
    }

    /**
     * POST /api/v1/usuarios/{userId}/apps/{appId}/permisos/reset-to-role-defaults
     *
     * Clears all scoped overrides for (user, app). The user's effective
     * permissions for the app revert to whatever the rol grants.
     */
    public function resetToRoleDefaults(int $userId, int $appId): JsonResponse
    {
        $deleted = $this->repository->deleteByUserAndApp($userId, $appId);

        return response()->json([
            'success' => true,
            'data' => [
                'usuario_id' => $userId,
                'app_id' => $appId,
                'removed_count' => $deleted,
            ],
            'message' => 'Permisos restablecidos a defaults del rol.',
        ]);
    }
}
