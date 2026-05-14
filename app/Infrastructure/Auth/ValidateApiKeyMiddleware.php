<?php

namespace App\Infrastructure\Auth;

use App\Application\UseCases\ValidateApiKeyUseCase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKeyMiddleware
{
    public function __construct(
        private ValidateApiKeyUseCase $validateApiKeyUseCase,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');

        // Extraer dominio del Origin o Referer
        $originDomain = $this->extractDomain($request);

        $result = $this->validateApiKeyUseCase->execute($apiKey, $originDomain);

        if (!$result['valid']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 401);
        }

        // Agregar info de la organización al request para uso posterior
        $request->attributes->set('organization_id', $result['organization_id']);
        $request->attributes->set('organization_name', $result['organization_name'] ?? null);

        return $next($request);
    }

    /**
     * Extraer dominio del request (Origin o Referer header).
     */
    private function extractDomain(Request $request): ?string
    {
        //优先用 Origin header (más seguro para CORS)
        $origin = $request->header('Origin');
        
        if ($origin) {
            return $this->parseDomain($origin);
        }

        // Fallback a Referer
        $referer = $request->header('Referer');
        
        if ($referer) {
            return $this->parseDomain($referer);
        }

        return null;
    }

    /**
     * Parsear dominio de una URL.
     */
    private function parseDomain(string $url): ?string
    {
        // Limpiar URLs типа "https://sailus.com/path"
        $parsed = parse_url($url);
        
        return $parsed['host'] ?? null;
    }
}