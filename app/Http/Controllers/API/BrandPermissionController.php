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

        $usuario = Usuario::find($userId);

        if (! $usuario) {
            return response()->json([
                'success' => false,
                'error' => 'USER_NOT_FOUND',
                'detail' => "User {$id} does not exist",
            ], 404);
        }

        // Filter the user's entidades to those with an open `propia`
        // pivot row. Commit 5.5 dropped `entidad.estado`; the
        // `propia` filter now goes through `entidad_relacion` instead
        // of a column on the entidad row.
        $brands = $usuario->entidades()
            ->whereIn('entidad.id', function ($q) {
                $q->select('entidad_id')
                    ->from('entidad_relacion')
                    ->whereIn('tipo_relacion', ['propia', 'interna'])
                    ->whereNull('effective_to');
            })
            ->get();

        // `dominio` is read via the Entidad accessor (presencia_online).
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
