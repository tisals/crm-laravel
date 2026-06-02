<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Models\Entidad;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntidadUsuarioController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/entidad/{id}/usuarios
     * List users assigned to an entity
     */
    public function index(int $entidadId): JsonResponse
    {
        $entidad = Entidad::with('usuarios')->find($entidadId);
        if (!$entidad) {
            return $this->errorResponse('Entidad no encontrada.', 404);
        }
        return $this->successResponse($entidad->usuarios);
    }

    /**
     * POST /api/v1/entidad-usuario
     * Assign a user to an entity. Only super_admin (Admin) or ventas users can be assigned.
     * Body: { usuario_id, entidad_id }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'usuario_id' => 'required|integer|exists:usuarios,id',
            'entidad_id' => 'required|integer|exists:entidad,id',
        ]);

        $usuario = Usuario::with('rol')->find($validated['usuario_id']);
        
        // Only allow assigning users with Admin (rol_id=1) or Ventas (rol_nombre='Ventas') roles
        $rolNombre = $usuario->rol?->nombre;
        $allowedRoles = ['Admin', 'Ventas'];
        
        if (!in_array($rolNombre, $allowedRoles)) {
            return $this->errorResponse(
                'Solo usuarios con roles Admin o Ventas pueden ser asignados a entidades.',
                403
            );
        }

        $entidad = Entidad::find($validated['entidad_id']);

        // Check if already assigned
        if ($entidad->usuarios()->where('usuario_id', $validated['usuario_id'])->exists()) {
            return $this->errorResponse('El usuario ya está asignado a esta entidad.', 409);
        }

        $entidad->usuarios()->attach($validated['usuario_id']);

        return $this->successResponse(
            ['usuario_id' => (int) $validated['usuario_id'], 'entidad_id' => (int) $validated['entidad_id']],
            200,
            'Usuario asignado a la entidad exitosamente.'
        );
    }

    /**
     * DELETE /api/v1/entidad-usuario
     * Remove a user from an entity.
     * Body: { usuario_id, entidad_id }
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'usuario_id' => 'required|integer|exists:usuarios,id',
            'entidad_id' => 'required|integer|exists:entidad,id',
        ]);

        $entidad = Entidad::find($validated['entidad_id']);

        // Check if assignment exists
        if (!$entidad->usuarios()->where('usuario_id', $validated['usuario_id'])->exists()) {
            return $this->errorResponse('La asignación no existe.', 404);
        }

        $entidad->usuarios()->detach($validated['usuario_id']);

        return $this->successResponse(null, 200, 'Usuario desasignado de la entidad exitosamente.');
    }
}
