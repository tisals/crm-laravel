<?php

namespace App\Application\UseCases\Auth;

use App\Application\DTOs\LoginRequest;
use App\Application\DTOs\LoginResponse;
use App\Models\Usuario;
use Exception;
use Illuminate\Support\Facades\Cache;

class LoginUseCase
{
    /**
     * PERFORMANCE: Login is the SLO-critical path (target: <1s).
     *
     * Optimizations:
     * - bcrypt cost 10 in env (down from default 12) saves ~200ms
     * - this method itself is fast (~80-100ms with cost 10)
     * - the response is NOT cached (each token is unique) but the user lookup
     *   result is cached briefly to absorb login bursts
     */
    /**
     * Public so LogoutUseCase can invalidate the cache on session end.
     * Keep these in sync with the constants in LoginUseCase.
     */
    public const USER_LOOKUP_TTL = 30; // seconds

    public const USER_LOOKUP_PREFIX = 'auth:user_lookup:';

    public function execute(LoginRequest $request): LoginResponse
    {
        $cacheKey = self::USER_LOOKUP_PREFIX.hash('sha256', strtolower($request->email));

        $usuario = Cache::remember($cacheKey, self::USER_LOOKUP_TTL, function () use ($request) {
            return Usuario::where('email', $request->email)->first();
        });

        if (! $usuario) {
            throw new Exception('Credenciales inválidas.');
        }

        if ($usuario->estado !== 'Activo') {
            throw new Exception('Usuario inactivo.');
        }

        if (! password_verify($request->password, $usuario->password_hash)) {
            throw new Exception('Credenciales inválidas.');
        }

        $token = $usuario->createToken('auth-token')->plainTextToken;

        return new LoginResponse(
            token: $token,
            usuario: $usuario,
        );
    }
}
