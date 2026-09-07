<?php

namespace App\Application\UseCases;

use App\Models\Entidad;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ValidateApiKeyUseCase
{
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

        $result = [
            'valid' => true,
            'bot_id' => "bot_{$entidad->id}",
            'name' => $entidad->nombre,
            'permissions' => [],
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }
}
