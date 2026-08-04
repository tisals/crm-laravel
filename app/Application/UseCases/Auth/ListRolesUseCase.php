<?php

namespace App\Application\UseCases\Auth;

use App\Application\Services\AuthAuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ListRolesUseCase
{
    private const CACHE_TTL = 300; // 5 minutes

    private const CACHE_PREFIX = 'auth:roles:';

    public function __construct(
        private AuthAuditService $audit,
    ) {}

    /**
     * Returns the list of all roles in the system.
     * Cached 5 minutes per (token hash, role-schema-version).
     *
     * @param  string  $tokenHash  SHA-256 of the Bearer token (for cache key)
     * @param  string  $ip  Caller IP
     * @param  string|null  $userAgent
     * @param  string|null  $requestId  X-Request-ID
     * @return array  ['roles' => array, 'total' => int, 'cached' => bool]
     */
    public function execute(string $tokenHash, string $ip, ?string $userAgent, ?string $requestId): array
    {
        $cacheKey = self::CACHE_PREFIX.hash('sha256', $tokenHash);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            $this->audit->log('roles.list.success', null, $ip, $userAgent, $requestId, ['cached' => true]);

            return [
                'roles' => $cached['roles'],
                'total' => $cached['total'],
                'cached' => true,
            ];
        }

        $roles = DB::select('SELECT id, nombre, slug FROM roles WHERE deleted_at IS NULL ORDER BY id ASC');

        $payload = [
            'roles' => array_map(function ($r) {
                return [
                    'id' => (int) $r->id,
                    'nombre' => $r->slug ?? $r->nombre,
                    'descripcion' => null,
                ];
            }, $roles),
            'total' => count($roles),
        ];

        Cache::put($cacheKey, $payload, self::CACHE_TTL);

        $this->audit->log('roles.list.success', null, $ip, $userAgent, $requestId, [
            'cached' => false,
            'total' => count($roles),
        ]);

        return [
            'roles' => $payload['roles'],
            'total' => $payload['total'],
            'cached' => false,
        ];
    }
}
