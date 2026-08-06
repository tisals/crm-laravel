<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Usuario\GetUserIdentityUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only endpoint that returns the full identity bundle for any user.
 * Used by the admin matrix UI to show rol defaults alongside scoped overrides.
 *
 * Auth + RBAC middleware ensures only admins (with `usuarios.apps.permisos.*`)
 * can call this.
 */
class UsuarioPermisoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GetUserIdentityUseCase $getUserIdentityUseCase,
    ) {}

    public function showIdentity(Request $request, int $userId): JsonResponse
    {
        $requestingUserId = $request->user()?->id;

        $bundle = $this->getUserIdentityUseCase->execute($userId, $requestingUserId);

        if ($bundle === null) {
            return $this->errorResponse('Usuario no encontrado.', 404);
        }

        return $this->successResponse($bundle);
    }
}
