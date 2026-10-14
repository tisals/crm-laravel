<?php

namespace App\Application\UseCases;

use App\Models\Entidad;
use Illuminate\Support\Facades\Cache;

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
     */
    public function execute(string $apiKey): ?array
    {
        $cacheKey = self::CACHE_PREFIX.hash('sha256', $apiKey);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Accept any non-Inactivo entity that has a dominio set. The original
        // filter was `estado = 'Activo'` only, but the BrandPermissionsSeeder
        // creates the home entities (Tecnoinnsoft, Desecurity) as `Propia`,
        // not `Activo`. Restricting auth to `Activo` only blocked the home
        // entities and the mercurio-crm-integration row from authenticating,
        // which broke the crm-laravel → mercurio → janus flow (empty apps and
        // tenants in the janus dashboard). Now any non-Inactivo entity with a
        // dominio can authenticate.
        $entidad = Entidad::where('dominio', $apiKey)
            ->where('estado', '!=', 'Inactivo')
            ->first();

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
