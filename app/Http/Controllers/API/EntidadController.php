<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Entidad\DestroyEntidadUseCase;
use App\Application\UseCases\Entidad\IndexEntidadUseCase;
use App\Application\UseCases\Entidad\ShowEntidadUseCase;
use App\Application\UseCases\Entidad\StoreEntidadUseCase;
use App\Application\UseCases\Entidad\UpdateEntidadUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\EntidadRequest;
use App\Infrastructure\Services\ActividadLogger;
use App\Models\Entidad;
use App\Traits\DispatchesWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EntidadController extends Controller
{
    use ApiResponse, DispatchesWebhooks;

    public function __construct(
        private IndexEntidadUseCase $indexUseCase,
        private ShowEntidadUseCase $showUseCase,
        private StoreEntidadUseCase $storeUseCase,
        private UpdateEntidadUseCase $updateUseCase,
        private DestroyEntidadUseCase $destroyUseCase,
    ) {}

    /**
     * Comprueba si el usuario autenticado puede acceder a la entidad:
     * - SuperAdmin y roles no Comercial: siempre permitido.
     * - Comercial: solo si la entidad está asignada a su usuario en `entidad_usuario`.
     *
     * Devuelve null si está permitido, o una JsonResponse 403 si no.
     */
    private function ensureCanAccessEntidad(int $entidadId): ?JsonResponse
    {
        $user = Auth::user();
        if (! $user) {
            return $this->errorResponse('No autenticado.', 401);
        }
        // Asegurar que la relación rol esté cargada (lazy-load no siempre
        // funciona bien al deserializar usuarios desde tokens de Sanctum).
        if (! $user->relationLoaded('rol')) {
            $user->load('rol');
        }
        $rolNombre = $user->rol?->nombre;
        if ($rolNombre !== 'Comercial') {
            return null; // SuperAdmin, Operaciones, Finanzas, etc.
        }

        // Verificar que la entidad existe
        if (! Entidad::whereKey($entidadId)->exists()) {
            return $this->errorResponse('Entidad no encontrada.', 404);
        }

        $isAssigned = DB::table('entidad_usuario')
            ->where('entidad_id', $entidadId)
            ->where('usuario_id', $user->id)
            ->exists();

        if (! $isAssigned) {
            return $this->errorResponse(
                'No tenés permisos para acceder a esta entidad. Solo podés ver las que tenés asignadas.',
                403
            );
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado', 'tipo_persona']);
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $result = $this->indexUseCase->execute($perPage, $search, $filters, $sortBy, $sortOrder);

        return $this->successResponse($result);
    }

    public function store(EntidadRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        // Dispatch webhook
        $this->dispatchWebhook($result, 'entidad.created', [
            'id' => $result->id,
            'nombre' => $result->nombre,
            'identificacion' => $result->identificacion,
            'tipo_persona' => $result->tipo_persona,
        ]);

        ActividadLogger::created(Auth::id(), "Marca creada: {$result->nombre}", 'Entidad', $result->id);

        return $this->successResponse($result, 201, 'Entidad creada exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        if ($resp = $this->ensureCanAccessEntidad($id)) {
            return $resp;
        }

        $result = $this->showUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('Entidad no encontrada.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(EntidadRequest $request, int $id): JsonResponse
    {
        if ($resp = $this->ensureCanAccessEntidad($id)) {
            return $resp;
        }

        $result = $this->updateUseCase->execute($id, $request->validated());

        if (! $result) {
            return $this->errorResponse('Entidad no encontrada.', 404);
        }

        // Dispatch webhook
        $this->dispatchWebhook($result, 'entidad.updated', [
            'id' => $result->id,
            'nombre' => $result->nombre,
            'identificacion' => $result->identificacion,
        ]);

        ActividadLogger::updated(Auth::id(), "Marca actualizada: {$result->nombre}", 'Entidad', $result->id);

        return $this->successResponse($result, 200, 'Entidad actualizada exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        if ($resp = $this->ensureCanAccessEntidad($id)) {
            return $resp;
        }

        $entidad = $this->showUseCase->execute($id);

        $result = $this->destroyUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('Entidad no encontrada.', 404);
        }

        // Dispatch webhook
        $this->dispatchWebhook($entidad, 'entidad.deleted', [
            'id' => $entidad->id,
            'nombre' => $entidad->nombre,
        ]);

        ActividadLogger::deleted(Auth::id(), "Marca eliminada: {$entidad->nombre}", 'Entidad', $entidad->id);

        return $this->successResponse(null, 200, 'Entidad eliminada exitosamente.');
    }
}
