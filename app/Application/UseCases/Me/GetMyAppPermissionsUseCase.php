<?php

namespace App\Application\UseCases\Me;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Returns the entities where the user has access to a specific app,
 * including the pivot metadata (estado, fecha_contrato, etc).
 *
 * Used by:
 *   GET /api/v1/me/apps/{slug}/permisos
 *
 * Returns null if the user has no access to the app at all.
 */
class GetMyAppPermissionsUseCase
{
    private const CACHE_TTL = 300; // 5 minutes

    private const CACHE_PREFIX = 'auth:me:apps:perms:';

    public function execute(int $userId, string $appSlug): ?array
    {
        $cacheKey = self::CACHE_PREFIX."{$userId}:{$appSlug}:v1";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId, $appSlug) {
            return $this->fetch($userId, $appSlug);
        });
    }

    private function fetch(int $userId, string $appSlug): ?array
    {
        $app = DB::connection('mysql_read')
            ->table('apps')
            ->where('slug', $appSlug)
            ->whereNull('deleted_at')
            ->first();

        if (! $app) {
            return null;
        }

        $entities = DB::connection('mysql_read')
            ->table('entidad_usuario')
            ->join('app_entidad', 'entidad_usuario.entidad_id', '=', 'app_entidad.entidad_id')
            ->join('entidad', 'entidad_usuario.entidad_id', '=', 'entidad.id')
            ->where('entidad_usuario.usuario_id', $userId)
            ->where('app_entidad.app_id', $app->id)
            ->where('app_entidad.estado', 'Activo')
            ->whereNull('entidad.deleted_at')
            ->select(
                'entidad.id as entidad_id',
                'entidad.nombre as entidad_nombre',
                'entidad.identificacion',
                'app_entidad.estado',
                'app_entidad.fecha_contrato',
                'app_entidad.fecha_vencimiento',
                'app_entidad.notas'
            )
            ->orderBy('entidad.nombre')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        return [
            'app' => [
                'id' => (int) $app->id,
                'slug' => $app->slug,
                'nombre' => $app->nombre,
                'tipo' => $app->tipo,
                'auth_type' => $app->auth_type,
            ],
            'permisos' => $entities,
            'total_entidades' => count($entities),
        ];
    }
}
