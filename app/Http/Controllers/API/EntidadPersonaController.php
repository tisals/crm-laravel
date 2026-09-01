<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Entidad;
use App\Models\Persona;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REST controller for the `entidad_persona` pivot (renamed from
 * `entidad_usuario` in commit fe99f70, see tenant-data-model-correction).
 *
 * The endpoint shape stays the same as before — `usuario_id` is still the
 * public-facing input — but the persistence layer resolves the user → persona
 * link (via `usuarios.persona_id` FK, NOT NULL after migration 000003)
 * and writes/reads `entidad_persona` (composite PK on persona_id+entidad_id).
 *
 * Naming rationale: the controller and route names match the new pivot
 * model (`EntidadPersona`). The legacy route path /api/v1/entidad-usuario
 * is preserved verbatim to avoid breaking external callers (FastAPI
 * integration tests, etc.) — only the backing class and pivot table
 * changed.
 */
class EntidadPersonaController extends Controller
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

        // Already assigned? (look up via the same pivot the relation uses)
        $exists = DB::table('entidad_persona as ep')
            ->join('usuarios as u', 'u.persona_id', '=', 'ep.persona_id')
            ->where('ep.entidad_id', $validated['entidad_id'])
            ->where('u.id', $validated['usuario_id'])
            ->exists();

        if ($exists) {
            return $this->errorResponse('El usuario ya está asignado a esta entidad.', 409);
        }

        // Resolve the user's persona_id (NOT NULL FK added in migration 000003)
        // and write the pivot row directly. We can't use the relation's
        // attach() because the relation is hasManyThrough (intermediate),
        // not belongsToMany — so we insert explicitly.
        DB::table('entidad_persona')->insert([
            'entidad_id' => (int) $validated['entidad_id'],
            'persona_id' => (int) $usuario->persona_id,
            'categoria' => 'asignacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        // Idempotent: if no assignment exists, treat as success
        $deleted = DB::table('entidad_persona as ep')
            ->join('usuarios as u', 'u.persona_id', '=', 'ep.persona_id')
            ->where('ep.entidad_id', $validated['entidad_id'])
            ->where('u.id', $validated['usuario_id'])
            ->delete();

        if ($deleted === 0) {
            return $this->successResponse(null, 200, 'La asignación no existía (idempotente).');
        }

        return $this->successResponse(null, 200, 'Usuario desasignado de la entidad exitosamente.');
    }
}
