<?php

namespace App\Infrastructure\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureUserHasApp — middleware that checks the authenticated user has the
 * given app in their app list (via the usuario_app pivot OR super-admin bypass).
 */
class EnsureUserHasApp
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        // Super-admin bypass
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $hasApp = $user->apps()->where('apps.slug', $slug)->exists();
        if (! $hasApp) {
            return response()->json([
                'success' => false,
                'error' => 'app_access_denied',
                'message' => "No tienes acceso a la app '{$slug}'.",
            ], 403);
        }

        return $next($request);
    }
}
