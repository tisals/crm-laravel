<?php

namespace App\Infrastructure\Auth;

use App\Application\Services\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RbacMiddleware
{
    public function __construct(
        private RbacService $rbacService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $routeName = $request->route()->getName();

        if (!$routeName) {
            return $next($request);
        }

        if (!$this->rbacService->hasPermission($user->rol_id, $routeName)) {
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
