<?php

namespace App\Application\UseCases\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ListUsersForSnapshotUseCase — janus-apps-canonicalization D-D3 follow-up (2026-10-15).
 *
 * Returns the flattened user list that the mercurio SnapshotRefresher
 * consumes to populate `mercurio_users_snapshot`. Endpoint:
 * `GET /api/v1/admin/users/snapshot` (auth: Bearer Sanctum token, must
 * be a SuperAdmin user per the controller's permission check).
 *
 * Output shape (per user, exactly what mercurio expects):
 *   {
 *     id, email, name, estado, rol_id,
 *     apps:       [{id, slug, nombre, tipo, auth_type, entidades_count, permisos: [vista]}],
 *     entities:   [{id, nombre}],
 *     permissions: [vista]   // deduped union of core rol + scoped user-app permisos
 *   }
 *
 * Response envelope: `{success: true, data: [<users>]}`. No pagination —
 * mercurio expects one big list (it loops over all rows).
 *
 * Active users only (estado = 'Activo'). Users with no apps and no
 * entities are STILL included — the downstream filter (Bug F in
 * mercurio) handles the suspicious-empty case, not us. We must not
 * drop them here or mercurio will see a stale snapshot.
 *
 * Performance: ~5 queries per user (users join roles, apps, app_entidad,
 * usuario_app_permisos, entidad_usuario). With 5 users in dev, ~25
 * queries — fine for an admin endpoint called every 60s. If this
 * scales past ~100 users, switch to a single query with JSON
 * aggregation.
 */
class ListUsersForSnapshotUseCase
{
    public function execute(): array
    {
        $conn = DB::connection();

        $userRows = $conn
            ->table('usuarios')
            ->whereNull('usuarios.deleted_at')
            ->where('usuarios.estado', 'Activo')
            ->select('usuarios.id')
            ->orderBy('usuarios.id')
            ->get();

        $users = [];
        foreach ($userRows as $u) {
            $bundle = $this->buildUserBundle($conn, (int) $u->id);
            if ($bundle !== null) {
                $users[] = $bundle;
            }
        }

        Log::info('list_users_for_snapshot.served', [
            'user_count' => count($users),
        ]);

        return $users;
    }

    /**
     * Build the snapshot row for a single user, mirroring
     * GetMyIdentityUseCase::computeFromDb so the shape is identical
     * to what /me/identity returns (per the janus-apps-canonicalization
     * D-D3 contract).
     *
     * Returns null if the user no longer exists or has no email
     * (email is required for the snapshot table).
     */
    private function buildUserBundle($conn, int $userId): ?array
    {
        $user = $conn
            ->table('usuarios')
            ->leftJoin('roles', 'usuarios.rol_id', '=', 'roles.id')
            ->where('usuarios.id', $userId)
            ->whereNull('usuarios.deleted_at')
            ->select(
                'usuarios.id',
                'usuarios.nombre',
                'usuarios.email',
                'usuarios.rol_id',
                'usuarios.estado',
                'roles.nombre as rol_nombre'
            )
            ->first();

        if (! $user) {
            return null;
        }

        $isAdmin = ((int) $user->rol_id === 1);

        // Apps: admins see the full catalog; non-admins see apps reachable
        // via their entidades. Matches GetMyIdentityUseCase logic.
        if ($isAdmin) {
            $appsQuery = $conn
                ->table('apps')
                ->leftJoin('app_entidad', function ($join) {
                    $join->on('app_entidad.app_id', '=', 'apps.id')
                        ->where('app_entidad.estado', 'Activo');
                })
                ->whereNull('apps.deleted_at');
        } else {
            $appsQuery = $conn
                ->table('entidad_usuario')
                ->join('app_entidad', 'entidad_usuario.entidad_id', '=', 'app_entidad.entidad_id')
                ->join('apps', 'app_entidad.app_id', '=', 'apps.id')
                ->where('entidad_usuario.usuario_id', $userId)
                ->where('app_entidad.estado', 'Activo')
                ->whereNull('apps.deleted_at');
        }

        $apps = $appsQuery
            ->groupBy('apps.id', 'apps.slug', 'apps.nombre', 'apps.tipo', 'apps.auth_type')
            ->select(
                'apps.id',
                'apps.slug',
                'apps.nombre',
                'apps.tipo',
                'apps.auth_type',
                DB::raw('COUNT(DISTINCT app_entidad.entidad_id) as entidades_count')
            )
            ->orderBy('apps.nombre')
            ->get()
            ->map(function ($row) use ($userId, $conn) {
                $permisos = $conn
                    ->table('usuario_app_permisos')
                    ->where('usuario_id', $userId)
                    ->where('app_id', $row->id)
                    ->whereNull('deleted_at')
                    ->orderBy('vista')
                    ->pluck('vista')
                    ->all();

                return [
                    'id' => (int) $row->id,
                    'slug' => $row->slug,
                    'nombre' => $row->nombre,
                    'tipo' => $row->tipo,
                    'auth_type' => $row->auth_type,
                    'entidades_count' => (int) $row->entidades_count,
                    'permisos' => $permisos,
                ];
            })
            ->all();

        // Entities: only brand/org memberships (exclude 'Cliente', 'Prospecto', 'Inactivo').
        $entities = $conn
            ->table('entidad_usuario as eu')
            ->join('entidad as e', 'e.id', '=', 'eu.entidad_id')
            ->where('eu.usuario_id', $userId)
            ->whereNotIn('e.estado', ['Cliente', 'Prospecto', 'Inactivo'])
            ->whereNull('e.deleted_at')
            ->orderBy('e.nombre')
            ->select('e.id', 'e.nombre')
            ->get()
            ->map(fn ($e) => ['id' => (int) $e->id, 'nombre' => $e->nombre])
            ->all();

        // Permissions: deduped union of core rol permisos + scoped user-app permisos.
        $corePermisos = $conn
            ->table('permisos')
            ->where('rol_id', $userId)
            ->whereNull('deleted_at')
            ->orderBy('vista')
            ->pluck('vista')
            ->all();

        $scopedPermisos = $conn
            ->table('usuario_app_permisos')
            ->where('usuario_id', $userId)
            ->whereNull('deleted_at')
            ->orderBy('vista')
            ->pluck('vista')
            ->all();

        $permissions = array_values(array_unique(array_merge($corePermisos, $scopedPermisos)));

        return [
            'id' => (int) $user->id,
            'email' => $user->email,
            'name' => $user->nombre,
            'estado' => $user->estado,
            'rol_id' => (int) $user->rol_id,
            'rol_nombre' => $user->rol_nombre,
            'apps' => $apps,
            'entities' => $entities,
            'permissions' => $permissions,
        ];
    }
}