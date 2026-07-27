<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
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
        if (! $entidad) {
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

        // Only allow assigning users with Comercial or SuperAdmin roles.
        // (The previous allowlist ['Admin', 'Ventas'] referenced roles that
        // do NOT exist in this project's `roles` table — the real ones are
        // SuperAdmin, Comercial, Operaciones, Finanzas. Without this fix,
        // the endpoint rejected every assignment with 403.)
        $rolNombre = $usuario->rol?->nombre;
        $allowedRoles = ['Comercial', 'SuperAdmin'];

        if (! in_array($rolNombre, $allowedRoles)) {
            return $this->errorResponse(
                "Solo usuarios con roles Comercial o SuperAdmin pueden ser asignados a entidades (rol recibido: {$rolNombre}).",
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
     * Body or Query: { usuario_id, entidad_id }
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'usuario_id' => 'required|integer|exists:usuarios,id',
            'entidad_id' => 'required|integer|exists:entidad,id',
        ]);

        $entidad = Entidad::find($validated['entidad_id']);

        // Idempotent: if assignment doesn't exist, treat as success
        if (! $entidad->usuarios()->where('usuario_id', $validated['usuario_id'])->exists()) {
            return $this->successResponse(null, 200, 'La asignación no existía (idempotente).');
        }

        $entidad->usuarios()->detach($validated['usuario_id']);

        return $this->successResponse(null, 200, 'Usuario desasignado de la entidad exitosamente.');
    }
}
