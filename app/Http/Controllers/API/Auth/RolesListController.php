<?php

namespace App\Http\Controllers\API\Auth;

use App\Application\UseCases\Auth\ListRolesUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolesListController extends Controller
{
    public function __construct(
        private ListRolesUseCase $useCase,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json([
                'success' => false,
                'error' => 'missing_token',
                'message' => 'Authorization Bearer token required',
            ], 401);
        }

        $tokenHash = hash('sha256', $token);
        $ip = $request->header('X-Forwarded-For')
            ? explode(',', $request->header('X-Forwarded-For'))[0]
            : $request->ip();
        $userAgent = $request->userAgent();
        $requestId = $request->header('X-Request-ID');

        $result = $this->useCase->execute($tokenHash, $ip, $userAgent, $requestId);

        return response()->json([
            'success' => true,
            'data' => $result['roles'],
            'meta' => [
                'total' => $result['total'],
                'validated_at' => now()->toIso8601String(),
            ],
        ])->header('X-Cache', $result['cached'] ? 'HIT' : 'MISS');
    }
}
