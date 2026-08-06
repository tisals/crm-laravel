<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Me\GetMyAppPermissionsUseCase;
use App\Application\UseCases\Me\GetMyAppsUseCase;
use App\Application\UseCases\Me\GetMyIdentityUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service endpoints. Returns data about the authenticated user
 * (apps they have access to, profile, permissions bundle, etc.) without
 * needing a user ID in the URL. Auth is always via Sanctum bearer token.
 */
class MeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GetMyAppsUseCase $getMyAppsUseCase,
        private GetMyAppPermissionsUseCase $getMyAppPermissionsUseCase,
        private GetMyIdentityUseCase $getMyIdentityUseCase,
    ) {}

    /**
     * GET /api/v1/me/apps
     * Apps the auth user has access to (transitively via entidades).
     */
    public function apps(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $result = $this->getMyAppsUseCase->execute($user->id);

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/me/apps/{slug}/permisos
     * Entities where the auth user has access to a specific app.
     */
    public function appPermisos(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $result = $this->getMyAppPermissionsUseCase->execute($user->id, $slug);

        if (! $result) {
            return $this->errorResponse("App '{$slug}' no encontrada o sin acceso.", 404);
        }

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/me/identity
     * Consolidated identity bundle: user + apps (each with their scoped
     * permissions) + deduped union of core + scoped permisos + rol info.
     * Powered by `user_identity_snapshot` (CQRS-Lite) with Redis cache.
     */
    public function identity(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $payload = $this->getMyIdentityUseCase->execute($user->id);

        if ($payload === null) {
            return $this->errorResponse('User not found.', 404);
        }

        return $this->successResponse($payload);
    }

    /**
     * GET /api/v1/me/permisos
     * Flat list of all permissions the user holds, computed as the
     * deduped union of:
     *   - core permissions (permisos.vista WHERE rol_id=user.rol_id)
     *   - scoped permissions (usuario_app_permisos.vista WHERE
     *     usuario_id=user.id AND app is in user's apps)
     */
    public function permisos(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $payload = $this->getMyIdentityUseCase->execute($user->id);

        if ($payload === null) {
            return $this->errorResponse('User not found.', 404);
        }

        return $this->successResponse([
            'permisos' => $payload['permisos'] ?? [],
            'scope_label' => $payload['scope_label'] ?? 'v1',
            'total' => count($payload['permisos'] ?? []),
        ]);
    }
}
