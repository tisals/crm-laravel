<?php

namespace App\Application\UseCases\Auth;

use App\Application\Services\AuthAuditService;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;

/**
 * TokenExchangeUseCase — minimal v1 implementation.
 *
 * Exchanges email + password for a Sanctum token. Returns the user shape
 * plus an empty `apps[]` array (apps enrichment is reserved for v2).
 *
 * Legacy compatibility endpoint for Mercurio v1 integration. New auth
 * multitenant multiapp architecture goes in v2.
 */
class TokenExchangeUseCase
{
    public function __construct(
        private AuthAuditService $audit,
    ) {}

    /**
     * @return array{token: string, access_token: string, usuario: array, user: array, apps: array, entidades: array, expires_at: string}|null
     */
    public function execute(string $email, string $password, string $ip, ?string $userAgent, ?string $requestId): ?array
    {
        $user = Usuario::where('email', $email)->first();

        if (! $user) {
            $this->audit->log('token_exchange.failed', null, $ip, $userAgent, $requestId, [
                'reason' => 'user_not_found',
                'email_domain' => substr($email, strpos($email, '@') ?: 0),
            ]);

            return null;
        }

        if (! Hash::check($password, $user->password_hash)) {
            $this->audit->log('token_exchange.failed', (int) $user->id, $ip, $userAgent, $requestId, [
                'reason' => 'invalid_credentials',
            ]);

            return null;
        }

        // Block inactive users (mirror LoginUseCase behaviour)
        if ($user->estado !== 'Activo') {
            $this->audit->log('token_exchange.failed', (int) $user->id, $ip, $userAgent, $requestId, [
                'reason' => 'inactive_user',
            ]);

            return null;
        }

        // Create Sanctum token with TTL configurable via env
        $ttl = (int) env('TOKEN_EXCHANGE_TTL', 3600);
        $expiresAt = now()->addSeconds($ttl);
        $token = $user->createToken('token-exchange', ['*'], $expiresAt)->plainTextToken;

        $usuarioShape = [
            'id' => (int) $user->id,
            'email' => $user->email,
            'nombres' => $user->nombre,
        ];

        $this->audit->log('token_exchange.success', (int) $user->id, $ip, $userAgent, $requestId, [
            'app_count' => 0,
            'entidades_count' => 0,
            'ttl' => $ttl,
        ]);

        return [
            // Project convention (Spanish)
            'token' => $token,
            'usuario' => $usuarioShape,
            'apps' => [],
            'entidades' => [],
            // OAuth-style aliases for external consumers (Mercurio v1)
            'access_token' => $token,
            'user' => $usuarioShape,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}