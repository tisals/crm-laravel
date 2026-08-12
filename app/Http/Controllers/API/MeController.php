<?php

namespace App\Http\Controllers\API;

use App\Application\Services\UserAppsResolver;
use App\Application\Services\UserEntidadesResolver;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private UserAppsResolver $appsResolver,
        private UserEntidadesResolver $entidadesResolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->successResponse([
            'id' => (int) $user->id,
            'email' => $user->email,
            'nombre' => $user->nombre,
            'apps' => $this->appsResolver->resolve($user),
            'entidades' => $this->entidadesResolver->resolve($user),
        ]);
    }

    public function apps(Request $request): JsonResponse
    {
        return $this->successResponse($this->appsResolver->resolve($request->user()));
    }

    public function appPermissions(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        $apps = $this->appsResolver->resolve($user);

        $hasApp = collect($apps)->firstWhere('slug', $slug);
        if (! $hasApp) {
            return $this->errorResponse('No tienes acceso a esta app.', 403);
        }

        return $this->successResponse(['permisos' => ['*']]);
    }
}
