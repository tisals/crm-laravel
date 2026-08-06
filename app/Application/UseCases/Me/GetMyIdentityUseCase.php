<?php

namespace App\Application\UseCases\Me;

use App\Models\UserIdentitySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Computes and returns the identity bundle for a user.
 *
 * Bundle shape:
 *   {
 *     user: {id, nombre, email, rol_id, rol_nombre, estado},
 *     apps: [{id, slug, nombre, tipo, permisos: [vista, ...]}],
 *     permisos: [vista, ...],   // deduped union of core + scoped across all apps
 *     rol: {id, nombre},
 *     scope_label: 'v1',
 *     snapshot_at: ISO8601 timestamp
 *   }
 *
 * Resolution order:
 *   1. Redis cache hit (key: auth:me:identity:{userId}:v1, TTL 5min).
 *   2. Snapshot row exists, fresh (computed_at < 26h ago) AND not stale:
 *      serve from snapshot + cache it.
 *   3. Otherwise (missing, stale, or marked is_stale=1): recompute from
 *      DB, persist the snapshot row with is_stale=0, cache it, return.
 *
 * If Redis is unreachable at any point, fall back to direct DB reads
 * (graceful degradation, AC06 in ADD).
 */
class GetMyIdentityUseCase
{
    /** Cache TTL: 5 minutes. */
    private const CACHE_TTL_SECONDS = 300;

    /** Snapshot staleness threshold — see ADD-AUTH-001 §6.5 (24h+2h slack). */
    private const STALENESS_HOURS = 26;

    private const CACHE_KEY_PREFIX = 'auth:me:identity:';

    public function execute(int $userId): ?array
    {
        $cacheKey = self::CACHE_KEY_PREFIX."{$userId}:v1";

        // 1. Try Redis cache first (graceful on Redis down).
        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        } catch (Throwable $e) {
            Log::warning('identity.cache.read_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Try the snapshot row.
        $row = UserIdentitySnapshot::find($userId);

        if ($this->isSnapshotFresh($row)) {
            $payload = $row->payload_decoded;
            $this->cachePut($cacheKey, $payload);

            return $payload;
        }

        // 3. Recompute from DB.
        $payload = $this->computeFromDb($userId);
        if ($payload === null) {
            return null;
        }

        $this->persist($userId, $payload);
        $this->cachePut($cacheKey, $payload);

        return $payload;
    }

    private function isSnapshotFresh(?UserIdentitySnapshot $row): bool
    {
        if (! $row) {
            return false;
        }

        if ($row->is_stale) {
            return false;
        }

        if (! $row->computed_at) {
            return false;
        }

        return $row->computed_at->gt(
            Carbon::now()->subHours(self::STALENESS_HOURS)
        );
    }

    /**
     * Live query: builds the bundle from the source tables. Returns null
     * if the user no longer exists.
     */
    private function computeFromDb(int $userId): ?array
    {
        $user = DB::connection('mysql_read')
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

        // Apps the user has access to (transitively via entidad).
        $apps = DB::connection('mysql_read')
            ->table('entidad_usuario')
            ->join('app_entidad', 'entidad_usuario.entidad_id', '=', 'app_entidad.entidad_id')
            ->join('apps', 'app_entidad.app_id', '=', 'apps.id')
            ->where('entidad_usuario.usuario_id', $userId)
            ->where('app_entidad.estado', 'Activo')
            ->whereNull('apps.deleted_at')
            ->groupBy('apps.id', 'apps.slug', 'apps.nombre', 'apps.tipo', 'apps.auth_type')
            ->select(
                'apps.id',
                'apps.slug',
                'apps.nombre',
                'apps.tipo',
                'apps.auth_type',
                DB::raw('COUNT(DISTINCT entidad_usuario.entidad_id) as entidades_count')
            )
            ->orderBy('apps.nombre')
            ->get()
            ->map(function ($row) use ($userId) {
                $permisos = DB::connection('mysql_read')
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

        // Deduped union of core rol permissions + all scoped permissions
        // across every app the user is in.
        $corePermisos = DB::connection('mysql_read')
            ->table('permisos')
            ->where('rol_id', $user->rol_id)
            ->whereNull('deleted_at')
            ->pluck('vista')
            ->all();

        $scopedPermisos = DB::connection('mysql_read')
            ->table('usuario_app_permisos as uap')
            ->join('app_entidad as ae', 'ae.app_id', '=', 'uap.app_id')
            ->join('entidad_usuario as eu', 'eu.entidad_id', '=', 'ae.entidad_id')
            ->where('eu.usuario_id', $userId)
            ->where('ae.estado', 'Activo')
            ->whereNull('uap.deleted_at')
            ->distinct()
            ->pluck('uap.vista')
            ->all();

        $permisos = array_values(array_unique(array_merge($corePermisos, $scopedPermisos)));
        sort($permisos);

        return [
            'user' => [
                'id' => (int) $user->id,
                'nombre' => $user->nombre,
                'email' => $user->email,
                'rol_id' => (int) $user->rol_id,
                'rol_nombre' => $user->rol_nombre,
                'estado' => $user->estado,
            ],
            'rol' => [
                'id' => (int) $user->rol_id,
                'nombre' => $user->rol_nombre,
            ],
            'apps' => $apps,
            'permisos' => $permisos,
            'scope_label' => 'v1',
            'snapshot_at' => Carbon::now()->toIso8601String(),
        ];
    }

    private function persist(int $userId, array $payload): void
    {
        $now = Carbon::now();

        DB::table('user_identity_snapshot')->updateOrInsert(
            ['user_id' => $userId],
            [
                'payload' => json_encode($payload),
                'scope_label' => $payload['scope_label'] ?? 'v1',
                'computed_at' => $now,
                'is_stale' => false,
            ]
        );
    }

    private function cachePut(string $key, array $payload): void
    {
        try {
            Cache::put($key, $payload, self::CACHE_TTL_SECONDS);
        } catch (Throwable $e) {
            Log::warning('identity.cache.write_failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
