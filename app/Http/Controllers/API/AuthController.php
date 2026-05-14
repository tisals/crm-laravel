<?php

namespace App\Http\Controllers\API;

use App\Application\DTOs\LoginRequest;
use App\Application\UseCases\Auth\LoginUseCase;
use App\Application\UseCases\Auth\LogoutUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private LoginUseCase $loginUseCase,
        private LogoutUseCase $logoutUseCase,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ], [
            'email.required' => 'El email es obligatorio.',
            'email.email' => 'El email debe ser válido.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        try {
            $loginRequest = LoginRequest::fromArray($request->only(['email', 'password']));
            $response = $this->loginUseCase->execute($loginRequest);

            return $this->successResponse($response->toArray());
        } catch (Exception $e) {
            $statusCode = $e->getMessage() === 'Usuario inactivo.' ? 401 : 401;

            return $this->errorResponse($e->getMessage(), $statusCode);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $this->logoutUseCase->execute($request);

        return $this->successResponse(null, 200, 'Sesión cerrada.');
    }

    public function validateKey(Request $request): JsonResponse
    {
        return $this->errorResponse('Not implemented yet.', 501);
    }
}
