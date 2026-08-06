<?php

namespace App\Application\UseCases\Usuario;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Returns the full identity bundle for an arbitrary user (admin-only).
 *
 * Different from GetMyIdentityUseCase (which reads the self-service snapshot):
 * - This one computes live (no snapshot), since it's used for admin previews
 *   where the target user_id is arbitrary and may not have a fresh snapshot.
 * - Cached 60s in Redis (admin traffic is lower, so 60s is OK).
 * - Includes `rol_defaults_by_app: { app_slug => [vista,...] }` so the admin
 *   matrix UI can show what each app inherits from the user's rol, even
 *   when those defaults are read from `permisos(rol_id, vista)` (not per app).
 *
 * Connection note: uses `mysql_read` when actually configured as a replica
 * (host/port different from `mysql`); otherwise falls back to the default
 * connection. This matches the BaseRepository pattern and avoids the
 * PDO transaction-isolation issue that breaks `RefreshDatabase` tests.
 */
class GetUserIdentityUseCase
{
    private const CACHE_TTL = 60; // 60s

    private const CACHE_PREFIX = 'auth:user:identity:';

    public function execute(int $targetUserId, ?int $requestingUserId = null): ?array
    {
        $cacheKey = self::CACHE_PREFIX."{$targetUserId}:{$requestingUserId}:v1";

        return cache()->remember($cacheKey, self::CACHE_TTL, function () use ($targetUserId) {
            return $this->compute($targetUserId);
        });
    }

    private function compute(int $targetUserId): ?array
    {
        $conn = $this->readConnection();

        // 1. Load user + rol.
        //    Note: `roles` table has NO `es_super_admin` column in main
        //    (was in feature/multi-app-access-and-fixes that never merged).
        //    SuperAdmin is identified by `permisos.vista='*'` for the rol.
        $user = DB::connection($conn)
            ->table('usuarios')
            ->join('roles', 'usuarios.rol_id', '=', 'roles.id')
            ->where('usuarios.id', $targetUserId)
            ->whereNull('usuarios.deleted_at')
            ->select(
                'usuarios.id',
                'usuarios.nombre',
                'usuarios.email',
                'usuarios.rol_id',
                'roles.nombre as rol_nombre',
                'usuarios.estado'
            )
            ->first();

        if (! $user) {
            return null;
        }

        // 2. Apps transitively assigned (user -> entidad_usuario -> app_entidad)
        $apps = DB::connection($conn)
            ->table('entidad_usuario')
            ->join('app_entidad', 'entidad_usuario.entidad_id', '=', 'app_entidad.entidad_id')
            ->join('apps', 'app_entidad.app_id', '=', 'apps.id')
            ->where('entidad_usuario.usuario_id', $targetUserId)
            ->where('app_entidad.estado', 'Activo')
            ->whereNull('apps.deleted_at')
            ->groupBy(
                'apps.id', 'apps.slug', 'apps.nombre', 'apps.tipo', 'apps.auth_type', 'apps.activo'
            )
            ->select(
                'apps.id',
                'apps.slug',
                'apps.nombre',
                'apps.tipo',
                'apps.auth_type',
                'apps.activo',
                DB::raw('COUNT(DISTINCT entidad_usuario.entidad_id) as entidades_count')
            )
            ->orderBy('apps.nombre')
            ->get()
            ->map(fn ($a) => (array) $a)
            ->toArray();

        // 3. Scoped permissions per app (user_app_permisos)
        $scoped = DB::connection($conn)
            ->table('usuario_app_permisos')
            ->where('usuario_id', $targetUserId)
            ->whereNull('deleted_at')
            ->select('app_id', 'vista')
            ->get();

        // 4. Rol default permissions (including `vista='*'` wildcard if present)
        $rolDefaults = DB::connection($conn)
            ->table('permisos')
            ->where('rol_id', $user->rol_id)
            ->whereNull('deleted_at')
            ->pluck('vista')
            ->toArray();

        $rolEsSuperAdmin = in_array('*', $rolDefaults, true);

        // 5. Compose scoped per app
        $scopedByApp = [];
        foreach ($apps as $a) {
            $scopedByApp[$a['slug']] = [];
        }
        foreach ($scoped as $s) {
            $slug = collect($apps)->firstWhere('id', $s->app_id)['slug'] ?? null;
            if ($slug) {
                $scopedByApp[$slug][] = $s->vista;
            }
        }

        // 6. Effective permissions = scoped ∪ rol_defaults (sans '*')
        $effective = collect($apps)->map(function ($a) use ($scopedByApp, $rolDefaults) {
            $slug = $a['slug'];
            $scopedSet = collect($scopedByApp[$slug] ?? [])->unique()->sort()->values();
            $effSet = $scopedSet->merge($rolDefaults)->unique()->sort()->values();

            return array_merge($a, [
                'permisos_scoped' => $scopedSet->toArray(),
                'permisos_efectivos' => $effSet->toArray(),
            ]);
        })->toArray();

        return [
            'user' => [
                'id' => (int) $user->id,
                'nombre' => $user->nombre,
                'email' => $user->email,
                'estado' => $user->estado,
                'rol' => [
                    'id' => (int) $user->rol_id,
                    'nombre' => $user->rol_nombre,
                    'es_super_admin' => $rolEsSuperAdmin,
                ],
            ],
            'rol_defaults' => collect($rolDefaults)->filter(fn ($v) => $v !== '*')->unique()->sort()->values()->toArray(),
            'apps' => $effective,
            'scope_label' => 'v1',
            'computed_at' => now()->toIso8601String(),
            'cache_ttl_seconds' => self::CACHE_TTL,
        ];
    }

    /**
     * Returns 'mysql_read' if a real read replica is configured (host/port
     * different from `mysql`); otherwise the default connection. This
     * mirrors the BaseRepository pattern added in Phase 2 to avoid
     * PDO transaction-isolation issues in tests.
     */
    private function readConnection(): string
    {
        $replica = config('database.connections.mysql_read');
        $master = config('database.connections.mysql');
        if (! $replica || ! $master) {
            return 'mysql';
        }
        $replicaHost = $replica['host'] ?? null;
        $masterHost = $master['host'] ?? null;
        $replicaPort = $replica['port'] ?? null;
        $masterPort = $master['port'] ?? null;

        if (($replicaHost !== $masterHost) || ($replicaPort !== $masterPort)) {
            return 'mysql_read';
        }

        return 'mysql';
    }
}
