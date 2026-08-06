<?php

namespace App\Infrastructure\Auth;

use App\Application\Services\MultiAppRbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC enforcement middleware.
 *
 * Looks up the user's effective permission for the current route using
 * `MultiAppRbacService`. The lookup is by user_id (not rol_id) so we can
 * leverage app-scoped overrides stored in `usuario_app_permisos` on top
 * of the core rol-based `permisos` table.
 *
 * App context:
 *   When the current route has an `{appId}` parameter (e.g. admin
 *   permission management endpoints), that ID is passed into the lookup
 *   so cross-app checks can be enforced. For routes without an `appId`
 *   binding, the lookup is global (rol permissions only).
 */
class RbacMiddleware
{
    public function __construct(
        private MultiAppRbacService $rbacService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $routeName = $request->route()->getName();

        if (! $routeName) {
            return $next($request);
        }

        // Extract app context from the route binding when present.
        // The admin endpoints use {appId} (camelCase), so we check both.
        $appId = $request->route('appId') ?? $request->route('app_id');
        if ($appId !== null) {
            $appId = (int) $appId;
        }

        if (! $this->rbacService->hasPermission($user->id, $appId, $routeName)) {
            // GET requests sin permiso → respuesta vacía en silencio (sin 403)
            if ($request->isMethod('GET')) {
                $isIndex = str_ends_with($routeName, '.index');
                if ($isIndex) {
                    return response()->json([
                        'success' => true,
                        'data' => ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1],
                    ]);
                }

                return response()->json(['success' => true, 'data' => null]);
            }

            return response()->json(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
