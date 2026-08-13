<?php

namespace App\Http\Controllers\API\Auth;

use App\Application\UseCases\Auth\TokenExchangeUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenExchangeController extends Controller
{
    public function __construct(
        private TokenExchangeUseCase $useCase,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:1',
        ], [
            'email.required' => 'El email es obligatorio.',
            'email.email' => 'El email debe ser válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        $ip = $request->header('X-Forwarded-For')
            ? explode(',', $request->header('X-Forwarded-For'))[0]
            : $request->ip();
        $userAgent = $request->userAgent();
        $requestId = $request->header('X-Request-ID');

        $result = $this->useCase->execute(
            $validated['email'],
            $validated['password'],
            $ip,
            $userAgent,
            $requestId,
        );

        if ($result === null) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_credentials',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 200);
    }
}