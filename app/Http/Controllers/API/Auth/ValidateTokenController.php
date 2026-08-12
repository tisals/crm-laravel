<?php

namespace App\Http\Controllers\API\Auth;

use App\Application\UseCases\Auth\ValidateTokenUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidateTokenController extends Controller
{
    public function __construct(
        private ValidateTokenUseCase $useCase,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => 'missing_token',
                'message' => 'This endpoint requires a Sanctum Bearer token. Use /api/v1/auth/validate-key for X-API-Key.',
            ], 401);
        }

        $ip = $request->header('X-Forwarded-For')
            ? explode(',', $request->header('X-Forwarded-For'))[0]
            : $request->ip();
        $userAgent = $request->userAgent();
        $requestId = $request->header('X-Request-ID');

        $result = $this->useCase->execute($token, $ip, $userAgent, $requestId);

        return response()->json([
            'success' => true,
            'data' => $result,
        ])->header('X-Cache', $result['cached'] ? 'HIT' : 'MISS');
    }
}
