<?php

namespace App\Infrastructure\Auth;

use App\Application\UseCases\ValidateApiKeyUseCase;
use App\Models\Entidad;
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

        if (!$apiKey) {
            return response()->json([
                'valid' => false,
                'error' => 'API key no proporcionada',
            ], 401);
        }

        $result = $this->validateApiKeyUseCase->execute($apiKey);

        if (!$result) {
            return response()->json([
                'valid' => false,
                'error' => 'API key inválida',
            ], 401);
        }

        // Extraer dominio del Origin/Referer para validación adicional
        $originDomain = $this->extractDomain($request);
        if ($originDomain) {
            $entidad = Entidad::where('dominio', $apiKey)->first();
            if ($entidad && !$entidad->isDomainAllowed($originDomain)) {
                return response()->json([
                    'valid' => false,
                    'error' => 'Dominio no autorizado',
                ], 401);
            }
        }

        // Agregar info de la organización al request
        $request->attributes->set('organization_id', $result['bot_id']);
        $request->attributes->set('organization_name', $result['name']);

        return $next($request);
    }

    private function extractDomain(Request $request): ?string
    {
        $origin = $request->header('Origin');
        if ($origin) {
            return $this->parseDomain($origin);
        }

        $referer = $request->header('Referer');
        if ($referer) {
            return $this->parseDomain($referer);
        }

        return null;
    }

    private function parseDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }
}
