<?php

namespace App\Application\UseCases;

use App\Models\Entidad;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ValidateApiKeyUseCase
{
    /**
     * Cache TTL for resolved API-key responses.
     *
     * Bumping this value extends how long stale `permissions` / `name`
     * snapshots can outlive an `app_entidad` mutation (e.g. an admin
     * revoking an app from a tenant mid-session). Lowering it forces
     * more DB hits on the hot path. 5 min was chosen as the worst-case
     * UX delay for a freshly-granted/revoked app to become visible to
     * m2m clients (BRP, SAIlus, WP-plugin) after a deploy.
     *
     * CACHE TTL BOUNDARY (additive note for the next reader):
     *  - Pre-rewrite cache entries from the OLD implementation (where
     *    `permissions` was always `[]`) expire naturally at this TTL.
     *    Do NOT flush the cache on rollout — `Cache::flush()` would also
     *    wipe every other auth session in the same Redis DB and force
     *    a global re-login. The 5-min boundary is the intended cutoff.
     *  - The cache key is `auth:api_key:<sha256($apiKey)>`, so old
     *    shapes and new shapes coexist on different keys only if the
     *    api-key itself changed — usually it does not, so a single
     *    tenant will see the new shape at most 300 s after deploy.
     */
    private const CACHE_TTL = 300; // 5 minutes

    private const CACHE_PREFIX = 'auth:api_key:';

    /**
     * Validates an API key and returns the entity metadata.
     *
     * PERFORMANCE: This endpoint is hit on every request from BRP, SAIlus, and
     * the WordPress plugin. Cached 5min per (sha256 of api_key) to avoid the
     * DB lookup. Cache invalidation happens automatically via TTL; if you need
     * to invalidate early (e.g. rotating keys), call Cache::forget() with the
     * same prefix.
     *
     * Commit 5.5 dropped `entidad.dominio` and `entidad.estado`. The
     * API-key lookup now resolves through:
     *   - `presencia_online.url`  (the canonical home for the legacy
     *      `dominio` value, typed as `tipo='web'` / `plataforma='otro'`),
     *   - `entidad_relacion.effective_to IS NULL` (the canonical "active"
     *      business-state pivot row that replaced `entidad.estado`).
     *
     * Permissions shape (per `Docs/openapi/auth.yaml`):
     *   `permissions` is a flat array of `apps.slug` values — the
     *   canonical app identifier — for every `app_entidad` row where
     *   `estado='Activo'`. Order is deterministic (alphabetical) so the
     *   cached payload bytewise-matches across deploys. Empty array
     *   (`[]`) when the entity has no active app contracts; never
     *   `null`.
     */
    public function execute(string $apiKey): ?array
    {
        $cacheKey = self::CACHE_PREFIX.hash('sha256', $apiKey);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $entidadId = DB::table('presencia_online as po')
            ->join('entidad_relacion as er', function ($join) {
                $join->on('er.entidad_id', '=', 'po.entidad_id')
                    ->whereNull('er.effective_to');
            })
            ->where('po.url', $apiKey)
            ->where('po.tipo', 'web')
            ->value('po.entidad_id');

        if (! $entidadId) {
            return null;
        }

        $entidad = Entidad::find($entidadId);

        if (! $entidad) {
            return null;
        }

        // Resolve the entidad's active app contracts.
        //
        // `app_entidad.estado='Activo'` is the canonical "this tenant
        // currently has access to this app" predicate. `apps.slug` is
        // the canonical app identifier (e.g. `mercurio`, `fama`,
        // `sailus`) — see `Docs/openapi/auth.yaml` and the `apps.slug`
        // unique index. We deliberately do NOT join on `apps.activo`:
        // the spec drives off the pivot's `estado` only, and a future
        // migration that retires `apps.activo` won't change this
        // query's contract.
        //
        // Sorted alphabetically to keep the cached payload byte-stable
        // across DB engines (MariaDB 10.11 vs SQLite for tests) and
        // across migrations that reorder pivot rows.
        $permissions = DB::table('app_entidad as ae')
            ->join('apps as a', 'a.id', '=', 'ae.app_id')
            ->where('ae.entidad_id', $entidadId)
            ->where('ae.estado', 'Activo')
            ->orderBy('a.slug')
            ->pluck('a.slug')
            ->all();

        $result = [
            'valid' => true,
            'bot_id' => "bot_{$entidad->id}",
            'name' => $entidad->nombre,
            'permissions' => $permissions,
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }
}
