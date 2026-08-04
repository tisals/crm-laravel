<?php

namespace App\Application\Services;

use App\Models\App;
use App\Models\Usuario;
use App\Models\UsuarioApp;

/**
 * UserAppsResolver — returns the apps assigned to a user.
 *
 * Decision logic (v1):
 *   - If user has role with es_super_admin=true → return ALL active apps
 *   - Otherwise → return apps from usuario_app (pivot with rol_id)
 */
class UserAppsResolver
{
    public function resolve(Usuario $user): array
    {
        if ($user->isSuperAdmin()) {
            return App::where('activo', true)
                ->get()
                ->map(function ($app) {
                    return [
                        'slug' => $app->slug,
                        'nombre' => $app->nombre,
                        'rol' => 'super-admin',
                        'rol_id' => $user->rol_id,
                    ];
                })
                ->toArray();
        }

        return UsuarioApp::where('usuario_id', $user->id)
            ->with(['app', 'rol'])
            ->get()
            ->map(function ($ua) {
                return [
                    'slug' => $ua->app->slug,
                    'nombre' => $ua->app->nombre,
                    'rol' => $ua->rol->slug ?? $ua->rol->nombre,
                    'rol_id' => $ua->rol_id,
                ];
            })
            ->toArray();
    }
}
