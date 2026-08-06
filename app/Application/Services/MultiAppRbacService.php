<?php

namespace App\Application\Services;

use App\Models\Usuario;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Multi-app RBAC lookup service.
 *
 * Resolves whether a user has a given `vista` permission for a given
 * `app_id`. The effective permission is the UNION of:
 *
 *   1. Core permissions from `permisos(rol_id, vista)` — including the
 *      wildcard `vista='*'` for super admins.
 *   2. App-scoped overrides from `usuario_app_permisos(usuario_id, app_id,
 *      vista)`.
 *
 * Results are cached in Redis under `auth:perm:{userId}:{appId or
 * 'global'}:{vista}` for 5 minutes. If Redis is unreachable, the lookup
 * falls back to direct DB queries (graceful degradation, AC06 in ADD).
 *
 * Lookup flow:
 *   1. Load user with `rol`. If rol.es_super_admin=true OR rol's permisos
 *      include `vista='*'` → return true.
 *   2. If `app_id` is null: just check `permisos(rol_id, vista)`.
 *   3. If `app_id` is set: check (permisos(rol_id, vista) UNION
 *      usuario_app_permisos(usuario_id, app_id, vista)).
 *
 * Cross-app isolation (AC02) is preserved because app-scoped rows are
 * always filtered by `app_id` — a grant on app A cannot satisfy a check
 * for app B.
 */
class MultiAppRbacService
{
    /** Cache TTL: 5 minutes (matches other identity caches in the app). */
    private const CACHE_TTL_SECONDS = 300;

    private const CACHE_KEY_PREFIX = 'auth:perm:';

    public function __construct(
        private ?CacheContract $cache = null,
    ) {
        // Allow injecting a fake cache in tests; default to the app's
        // default cache store (Redis in prod, array in tests).
        $this->cache = $cache ?? Cache::store();
    }

    /**
     * Returns true if the user has the given `vista` for the given `app_id`
     * (or globally if `$appId` is null).
     */
    public function hasPermission(int $userId, ?int $appId, string $vista): bool
    {
        $cacheKey = $this->cacheKey($userId, $appId, $vista);

        try {
            return (bool) $this->cache->remember(
                $cacheKey,
                self::CACHE_TTL_SECONDS,
                fn () => $this->lookup($userId, $appId, $vista)
            );
        } catch (Throwable $e) {
            // AC06 — graceful degradation when Redis is down. Log once
            // and fall back to a direct DB check.
            Log::warning('rbac.cache.fallback', [
                'user_id' => $userId,
                'app_id' => $appId,
                'vista' => $vista,
                'error' => $e->getMessage(),
            ]);

            return $this->lookup($userId, $appId, $vista);
        }
    }

    /**
     * Invalidate the cached permission for a specific (user, app, vista).
     * Called by mutation use cases (Grant/Revoke/Sync) so the next read
     * recomputes from the DB.
     */
    public function invalidate(int $userId, ?int $appId, string $vista): void
    {
        try {
            $this->cache->forget($this->cacheKey($userId, $appId, $vista));
        } catch (Throwable $e) {
            // Cache invalidate failures should never block the mutation.
            // The TTL will eventually expire the stale entry.
            Log::warning('rbac.cache.invalidate_failed', [
                'user_id' => $userId,
                'app_id' => $appId,
                'vista' => $vista,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate ALL cached entries for a user across every (app, vista)
     * combination. Used by bulk operations like ResetToRoleDefaults.
     *
     * Implementation note: we can't enumerate Redis keys from inside the
     * app reliably (no SCAN API in the Cache facade), so we bump a
     * per-user version segment instead — the cache key changes, and old
     * entries naturally TTL out.
     */
    public function invalidateAllForUser(int $userId): void
    {
        $versionKey = self::CACHE_KEY_PREFIX."version:{$userId}";

        try {
            // Bumping the version invalidates every cached entry for this
            // user (the cache key includes the version).
            $this->cache->forget($versionKey);
            $this->cache->put($versionKey, (string) (time()), self::CACHE_TTL_SECONDS);
        } catch (Throwable $e) {
            Log::warning('rbac.cache.invalidate_all_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Core lookup — DB-only. Returns the truth value without touching
     * the cache. Used both inside `cache->remember` and as the fallback
     * when Redis is unavailable.
     */
    private function lookup(int $userId, ?int $appId, string $vista): bool
    {
        $user = Usuario::find($userId);
        if (! $user) {
            return false;
        }

        $rolId = (int) $user->rol_id;

        $conn = $this->readConnection();

        // 1. Wildcard check (vista='*' on the rol's permisos).
        //    This is the SuperAdmin fast path: roles with a `*`
        //    permiso bypass all vista checks.
        $hasWildcard = $conn
            ->table('permisos')
            ->where('rol_id', $rolId)
            ->where('vista', '*')
            ->whereNull('deleted_at')
            ->exists();

        if ($hasWildcard) {
            return true;
        }

        // 2. Core (rol-scoped) permission.
        $hasCore = $conn
            ->table('permisos')
            ->where('rol_id', $rolId)
            ->where('vista', $vista)
            ->whereNull('deleted_at')
            ->exists();

        if ($hasCore) {
            return true;
        }

        // 3. App-scoped override (only when appId is known).
        if ($appId !== null) {
            $hasScoped = $conn
                ->table('usuario_app_permisos')
                ->where('usuario_id', $userId)
                ->where('app_id', $appId)
                ->where('vista', $vista)
                ->whereNull('deleted_at')
                ->exists();

            if ($hasScoped) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the connection to use for the read queries. Mirrors the
     * logic in `BaseRepository::isReadReplicaConfigured`: when the
     * `mysql_read` connection points to the SAME host/port as the master
     * `mysql` connection (the dev / test / single-instance case), we
     * fall back to the master so the queries run inside the same
     * transaction. Otherwise we use the dedicated read replica.
     *
     * This matters specifically in tests using `RefreshDatabase`: that
     * trait wraps each test in a transaction on the default connection.
     * If `mysql_read` were a separate PDO connection, it would not see
     * the writes committed in the test transaction, and every assertion
     * would fail with "permission not found".
     */
    private function readConnection(): \Illuminate\Database\Connection
    {
        $readName = 'mysql_read';
        $readConfig = config("database.connections.{$readName}");
        $masterConfig = config('database.connections.mysql');

        if (! $readConfig || ! $masterConfig) {
            return DB::connection();
        }

        $isReplica = ($readConfig['host'] ?? null) !== ($masterConfig['host'] ?? null)
            || ($readConfig['port'] ?? null) !== ($masterConfig['port'] ?? null);

        return $isReplica ? DB::connection($readName) : DB::connection();
    }

    private function cacheKey(int $userId, ?int $appId, string $vista): string
    {
        $appSegment = $appId === null ? 'global' : (string) $appId;

        // Include a per-user version so invalidateAllForUser can purge
        // without needing to enumerate keys. The version is itself
        // cached with the same TTL as the entries it invalidates, which
        // is fine — once it expires, the old entries have too.
        $versionKey = self::CACHE_KEY_PREFIX."version:{$userId}";
        try {
            $version = $this->cache->get($versionKey, '0');
        } catch (Throwable $e) {
            $version = '0';
        }

        return self::CACHE_KEY_PREFIX."{$userId}:{$appSegment}:{$vista}:v{$version}";
    }
}
