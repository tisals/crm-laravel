<?php

namespace App\Http\Controllers\API\Concerns;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    public function successResponse(mixed $data = null, int $code = 200, ?string $message = null): JsonResponse
    {
        $payload = ['success' => true];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $code);
    }

    public function errorResponse(string $message, int $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
        ], $code);
    }
}
