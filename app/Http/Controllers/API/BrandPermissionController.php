<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;

class BrandPermissionController extends Controller
{
    /**
     * GET /api/v1/users/{id}/brands
     * 
     * Obtiene las marcas (entidades tipo 'Propia') que un usuario
     * tiene permiso de gestionar para notificaciones de marketing.
     * 
     * Consumido por SAIlus FastAPI → services/crm_client.py
     */
    public function index(string $id): JsonResponse
    {
        $userId = (int) $id;

        $usuario = Usuario::with('entidades')->find($userId);

        if (!$usuario) {
            return response()->json([
                'success' => false,
                'error' => 'USER_NOT_FOUND',
                'detail' => "User {$id} does not exist",
            ], 404);
        }

        // Filtrar entidades con estado 'Propia' (marcas internas)
        $brands = $usuario->entidades()
            ->whereIn('entidad.estado', ['Propia', 'Interna'])
            ->get();

        // Usar el dominio como brand_key
        $brandPermissions = $brands
            ->pluck('dominio')
            ->filter()
            ->values()
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $id,
                'organization_id' => $brands->first()?->id,
                'brand_permissions' => $brandPermissions,
            ],
        ]);
    }
}
