<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Entidad\IndexEntidadUseCase;
use App\Application\UseCases\Entidad\ShowEntidadUseCase;
use App\Application\UseCases\Entidad\StoreEntidadUseCase;
use App\Application\UseCases\Entidad\UpdateEntidadUseCase;
use App\Application\UseCases\Entidad\DestroyEntidadUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\EntidadRequest;
use App\Traits\DispatchesWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado', 'tipo_persona']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

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

        return $this->successResponse($result, 201, 'Entidad creada exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Entidad no encontrada.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(EntidadRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Entidad no encontrada.', 404);
        }

        // Dispatch webhook
        $this->dispatchWebhook($result, 'entidad.updated', [
            'id' => $result->id,
            'nombre' => $result->nombre,
            'identificacion' => $result->identificacion,
        ]);

        return $this->successResponse($result, 200, 'Entidad actualizada exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $entidad = $this->showUseCase->execute($id);
        
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Entidad no encontrada.', 404);
        }

        // Dispatch webhook (usar datos antes de eliminar)
        if ($entidad) {
            $this->dispatchWebhook($entidad, 'entidad.deleted', [
                'id' => $entidad->id,
                'nombre' => $entidad->nombre,
                'identificacion' => $entidad->identificacion,
            ]);
        }

        return $this->successResponse(null, 200, 'Entidad eliminada exitosamente.');
    }
}
