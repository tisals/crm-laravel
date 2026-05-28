<?php

namespace App\Http\Controllers\API;

use App\Application\DTOs\LoginRequest;
use App\Application\UseCases\Auth\LoginUseCase;
use App\Application\UseCases\Auth\LogoutUseCase;
use App\Application\UseCases\ValidateApiKeyUseCase;
use App\Infrastructure\Services\ActividadLogger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use Exception;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private LoginUseCase $loginUseCase,
        private LogoutUseCase $logoutUseCase,
        private ValidateApiKeyUseCase $validateKeyUseCase,
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

            ActividadLogger::login($response->usuario->id, $response->usuario->email);

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

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'El email es obligatorio.',
            'email.email' => 'El email debe ser válido.',
            'email.exists' => 'No encontramos un usuario con ese email.',
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return $this->successResponse(null, 200, 'Te hemos enviado un enlace para restablecer tu contraseña.');
        }

        return $this->errorResponse('No se pudo enviar el enlace de restablecimiento.', 500);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'email.required' => 'El email es obligatorio.',
            'token.required' => 'El token es obligatorio.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => bcrypt($password),
                ])->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->successResponse(null, 200, 'Contraseña restablecida exitosamente.');
        }

        return $this->errorResponse('No se pudo restablecer la contraseña. Token inválido o expirado.', 400);
    }

    public function validateKey(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey) {
            return response()->json([
                'valid' => false,
                'error' => 'API key no proporcionada',
            ], 401);
        }

        $result = $this->validateKeyUseCase->execute($apiKey);

        if (!$result) {
            return response()->json([
                'valid' => false,
                'error' => 'API key inválida',
            ], 401);
        }

        return response()->json($result);
    }
}
