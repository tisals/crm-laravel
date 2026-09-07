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

        if (! $apiKey) {
            return response()->json([
                'valid' => false,
                'error' => 'API key no proporcionada',
            ], 401);
        }

        $result = $this->validateApiKeyUseCase->execute($apiKey);

        if (! $result) {
            return response()->json([
                'valid' => false,
                'error' => 'API key inválida',
            ], 401);
        }

        // Extraer dominio del Origin/Referer para validación adicional.
        // Pre-Commit 5.5 the lookup went through `entidad.dominio` —
        // that column was dropped in favour of `presencia_online.url`
        // (typed as `tipo='web'`). The Entidad model
        // `dominio` accessor still resolves the value via the canonical
        // presencia_online row, so `Entidad::where` against the accessor
        // here would translate to a sub-query — for the auth path we
        // simply resolve the entidad through `presencia_online` directly.
        $originDomain = $this->extractDomain($request);
        if ($originDomain) {
            $entidad = \Illuminate\Support\Facades\DB::table('presencia_online as po')
                ->join('entidad as e', 'e.id', '=', 'po.entidad_id')
                ->where('po.url', $apiKey)
                ->where('po.tipo', 'web')
                ->select('e.*')
                ->first();
            if ($entidad && ! $entidad->isDomainAllowed($originDomain)) {
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
