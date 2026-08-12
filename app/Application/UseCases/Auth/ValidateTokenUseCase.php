<?php

namespace App\Application\UseCases\Auth;

use App\Application\Services\AuthAuditService;
use App\Application\Services\UserAppsResolver;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

class ValidateTokenUseCase
{
    private const CACHE_TTL = 300; // 5 minutes

    private const CACHE_PREFIX = 'auth:validate:';

    public function __construct(
        private UserAppsResolver $appsResolver,
        private AuthAuditService $audit,
    ) {}

    /**
     * Validates a Sanctum token and returns user info, apps, and permisos.
     * Cached 5min per token hash.
     *
     * @return array{valid: bool, usuario_id: int|null, email: string|null, apps: array, permisos: array, cached: bool}
     */
    public function execute(string $token, string $ip, ?string $userAgent, ?string $requestId): array
    {
        $hash = hash('sha256', $token);
        $cacheKey = self::CACHE_PREFIX.$hash;

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            $cached['cached'] = true;
            $this->audit->log('validate_token.success', $cached['usuario_id'] ?? null, $ip, $userAgent, $requestId, ['cached' => true]);

            return $cached;
        }

        $pat = PersonalAccessToken::findToken($token);
        if (! $pat) {
            return ['valid' => false, 'usuario_id' => null, 'email' => null, 'apps' => [], 'permisos' => [], 'cached' => false];
        }

        $user = $pat->tokenable;
        $apps = $this->appsResolver->resolve($user);

        $payload = [
            'valid' => true,
            'usuario_id' => (int) $user->id,
            'email' => $user->email,
            'apps' => $apps,
            'permisos' => ['*'], // super-admin bypass; future: per-rol permissions
            'cached' => false,
        ];

        Cache::put($cacheKey, $payload, self::CACHE_TTL);

        $this->audit->log('validate_token.success', (int) $user->id, $ip, $userAgent, $requestId, ['cached' => false]);

        return $payload;
    }
}
