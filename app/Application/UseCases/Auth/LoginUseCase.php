<?php

namespace App\Application\UseCases\Auth;

use App\Application\DTOs\LoginRequest;
use App\Application\DTOs\LoginResponse;
use App\Application\Services\UserAppsResolver;
use App\Models\Usuario;
use Exception;

class LoginUseCase
{
    public function __construct(
        private UserAppsResolver $appsResolver,
    ) {}

    public function execute(LoginRequest $request): LoginResponse
    {
        $usuario = Usuario::where('email', $request->email)->first();

        if (! $usuario) {
            throw new Exception('Credenciales inválidas.');
        }

        // Fix-9: account-enumeration mitigation — don't reveal that the user is inactive.
        // The error message is identical to "credenciales inválidas" so the client
        // cannot distinguish between "user not found", "wrong password", "inactive".
        if ($usuario->estado !== 'Activo') {
            throw new Exception('Credenciales inválidas.');
        }

        if (! password_verify($request->password, $usuario->password_hash)) {
            throw new Exception('Credenciales inválidas.');
        }

        $token = $usuario->createToken('auth-token')->plainTextToken;

        return new LoginResponse(
            token: $token,
            usuario: $usuario,
            apps: $this->appsResolver->resolve($usuario),
        );
    }
}
