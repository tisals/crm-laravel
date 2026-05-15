<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $planes = Producto::where('tipo', 'suscripcion')
            ->where('estado', 'Activo')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->nombre,
                'price' => $p->precio,
                'description' => $p->descripcion,
                'features' => $p->caracteristicas ?? [],
            ]);

        return $this->successResponse($planes);
    }
}
