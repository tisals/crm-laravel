<?php

namespace App\Application\UseCases\Me;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Returns the apps the authenticated user has access to, derived
 * transitively:
 *
 *   user -> entidad_usuario -> entidades -> app_entidad (estado='Activo')
 *        -> apps
 *
 * The deduped union across all the user's entities.
 *
 * Cached 5min per (user_id, app-set-version) so that mass-assign ops
 * eventually propagate. The version segment is opaque ("v1") so we
 * can bump it later if we need a manual cache bust.
 */
class GetMyAppsUseCase
{
    private const CACHE_TTL = 300; // 5 minutes

    private const CACHE_PREFIX = 'auth:me:apps:';

    public function execute(int $userId): array
    {
        $cacheKey = self::CACHE_PREFIX."{$userId}:v1";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            return $this->fetch($userId);
        });
    }

    private function fetch(int $userId): array
    {
        $rows = DB::connection('mysql_read')
            ->table('entidad_usuario')
            ->join('app_entidad', 'entidad_usuario.entidad_id', '=', 'app_entidad.entidad_id')
            ->join('apps', 'app_entidad.app_id', '=', 'apps.id')
            ->where('entidad_usuario.usuario_id', $userId)
            ->where('app_entidad.estado', 'Activo')
            ->whereNull('apps.deleted_at')
            ->groupBy(
                'apps.id',
                'apps.slug',
                'apps.nombre',
                'apps.tipo',
                'apps.auth_type',
                'apps.activo'
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
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'apps' => $rows,
            'total' => count($rows),
        ];
    }
}
