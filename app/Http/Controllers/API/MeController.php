<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Me\GetMyAppPermissionsUseCase;
use App\Application\UseCases\Me\GetMyAppsUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service endpoints. Returns data about the authenticated user
 * (apps they have access to, profile, etc.) without needing a user
 * ID in the URL. Auth is always via Sanctum bearer token.
 */
class MeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GetMyAppsUseCase $getMyAppsUseCase,
        private GetMyAppPermissionsUseCase $getMyAppPermissionsUseCase,
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
}
