<?php

namespace App\Application\UseCases\Auth;

use App\Application\DTOs\LoginRequest;
use App\Application\DTOs\LoginResponse;
use App\Models\Usuario;
use Exception;

class LoginUseCase
{
    public function execute(LoginRequest $request): LoginResponse
    {
        $usuario = Usuario::where('email', $request->email)->first();

        if (!$usuario) {
            throw new Exception('Credenciales inválidas.');
        }

        if ($usuario->estado !== 'Activo') {
            throw new Exception('Usuario inactivo.');
        }

        if (!password_verify($request->password, $usuario->password_hash)) {
            throw new Exception('Credenciales inválidas.');
        }

        $token = $usuario->createToken('auth-token')->plainTextToken;

        return new LoginResponse(
            token: $token,
            usuario: $usuario,
        );
    }
}
