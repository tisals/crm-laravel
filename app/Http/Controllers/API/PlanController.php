<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PlanController extends Controller
{
    use ApiResponse;

    /**
     * PERFORMANCE: This is a public endpoint hit on every landing page view
     * by unauthenticated users. Cache 1h since plans change rarely.
     * Invalidated manually when a plan is created/updated (handled elsewhere).
     */
    private const CACHE_TTL = 3600; // 1 hour

    private const CACHE_KEY = 'public:planes:suscripciones';

    public function index(): JsonResponse
    {
        $planes = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return Producto::where('tipo', 'suscripcion')
                ->where('estado', 'Activo')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->nombre,
                    'price' => $p->precio,
                    'description' => $p->descripcion,
                    'features' => $p->caracteristicas ?? [],
                ])
                ->toArray();
        });

        return $this->successResponse($planes);
    }
}
