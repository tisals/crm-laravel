<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiAccessLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        // Procesar request
        $response = $next($request);

        // Calcular duración
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // Recopilar información de contexto
        $context = $this->buildContext($request, $response, $duration);

        // Loguear según el código de respuesta
        $this->logAccess($context);

        return $response;
    }

    private function buildContext(Request $request, Response $response, float $duration): array
    {
        // Identificar usuario/API key
        $userId = null;
        $userType = 'guest';
        $organizationId = null;

        // Si es Sanctum authenticated
        if ($request->user()) {
            $userId = $request->user()->id;
            $userType = 'usuario';
            $organizationId = $request->user()->id; // Los usuarios tienen acceso total
        }

        // Si es API Key
        if ($request->attributes->has('organization_id')) {
            $organizationId = $request->attributes->get('organization_id');
            $userType = 'api_key';
            $userId = 'api_key:' . $organizationId;
        }

        return [
            // Request info
            'method' => $request->method(),
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            
            // Auth info
            'user_id' => $userId,
            'user_type' => $userType,
            'organization_id' => $organizationId,
            
            // Response info
            'status_code' => $response->getStatusCode(),
            'duration_ms' => $duration,
            
            // Request metadata
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'origin' => $request->header('Origin'),
        ];
    }

    private function logAccess(array $context): void
    {
        // Separar logs por nivel de severidad
        $statusCode = $context['status_code'];

        if ($statusCode >= 500) {
            // Error del servidor
            Log::error('API Error', $context);
        } elseif ($statusCode >= 400) {
            // Error del cliente (4xx)
            Log::warning('API Client Error', $context);
        } else {
            // Éxito (2xx, 3xx)
            Log::channel('api')->info('API Access', $context);
        }
    }
}