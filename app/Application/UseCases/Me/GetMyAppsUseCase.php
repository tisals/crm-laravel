<?php

namespace App\Application\UseCases\Me;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Returns the apps the authenticated user has access to, derived
 * transitively:
 *
 *   user -> usuarios.persona_id -> entidad_persona -> entidades
 *        -> app_entidad (estado='Activo') -> apps
 *
 * The deduped union across all the user's entities.
 *
 * Per `tenant-data-model-correction` (commit fe99f70): the user ↔ entidad
 * pivot is now `entidad_persona` (keyed on persona_id), resolved via the
 * NOT NULL `usuarios.persona_id` FK added in migration 000003.
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
            ->table('entidad_persona')
            ->join('usuarios', 'usuarios.persona_id', '=', 'entidad_persona.persona_id')
            ->join('app_entidad', 'entidad_persona.entidad_id', '=', 'app_entidad.entidad_id')
            ->join('apps', 'app_entidad.app_id', '=', 'apps.id')
            ->where('usuarios.id', $userId)
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
                DB::raw('COUNT(DISTINCT entidad_persona.entidad_id) as entidades_count')
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
