<?php

namespace App\Application\UseCases\Auth;

use App\Application\Services\AuthAuditService;
use App\Application\Services\UserAppsResolver;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;

/**
 * TokenExchangeUseCase — exchanges email+password for a Sanctum token,
 * returning the user's apps plus (later) entidades.
 *
 * Strict TDD — added console command for backfill.
 */
class TokenExchangeUseCase
{
    public function __construct(
        private UserAppsResolver $appsResolver,
        private AuthAuditService $audit,
    ) {}

    /**
     * @return array{token: string, usuario: array, apps: array, entidades: array, expires_at: string}|null
     */
    public function execute(string $email, string $password, string $ip, ?string $userAgent, ?string $requestId): ?array
    {
        $user = Usuario::where('email', $email)->first();

        if (! $user) {
            $this->audit->log('token_exchange.failed', null, $ip, $userAgent, $requestId, ['reason' => 'user_not_found', 'email_domain' => substr($email, strpos($email, '@') ?: 0)]);

            return null;
        }

        if (! Hash::check($password, $user->password_hash)) {
            $this->audit->log('token_exchange.failed', (int) $user->id, $ip, $userAgent, $requestId, ['reason' => 'invalid_credentials']);

            return null;
        }

        // Create Sanctum token with TTL configurable via env
        $ttl = (int) env('TOKEN_EXCHANGE_TTL', 3600);
        $expiresAt = now()->addSeconds($ttl);
        $token = $user->createToken('token-exchange', ['*'], $expiresAt)->plainTextToken;

        $apps = $this->appsResolver->resolve($user);

        $payload = [
            'token' => $token,
            'usuario' => [
                'id' => (int) $user->id,
                'email' => $user->email,
                'nombres' => $user->nombre,
            ],
            'apps' => $apps,
            'entidades' => [], // Phase 4: fill when UserEntidadesResolver is wired
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $this->audit->log('token_exchange.success', (int) $user->id, $ip, $userAgent, $requestId, [
            'app_count' => count($apps),
            'entidades_count' => 0,
            'ttl' => $ttl,
        ]);

        return $payload;
    }
}
