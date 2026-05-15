<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Models\Entidad;
use Illuminate\Http\JsonResponse;

class SailusEntidadController extends Controller
{
    use ApiResponse;

    public function show(int $id): JsonResponse
    {
        $entidad = Entidad::find($id);

        if (!$entidad) {
            return $this->errorResponse('Cuenta no encontrada', 404);
        }

        return $this->successResponse([
            'id' => $entidad->id,
            'nombre' => $entidad->nombre,
            'plan_type' => null, // No hay mapeo plan→entidad aún
            'status' => $entidad->estado,
        ]);
    }
}
