<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\OrdenServicio\IndexOrdenServicioUseCase;
use App\Application\UseCases\OrdenServicio\ShowOrdenServicioUseCase;
use App\Application\UseCases\OrdenServicio\StoreOrdenServicioUseCase;
use App\Application\UseCases\OrdenServicio\UpdateOrdenServicioUseCase;
use App\Application\UseCases\OrdenServicio\DestroyOrdenServicioUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\OrdenServicioRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrdenServicioController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexOrdenServicioUseCase $indexUseCase,
        private ShowOrdenServicioUseCase $showUseCase,
        private StoreOrdenServicioUseCase $storeUseCase,
        private UpdateOrdenServicioUseCase $updateUseCase,
        private DestroyOrdenServicioUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $filters = $request->only(['estado', 'colaborador_id', 'proveedor_id']);

        $result = $this->indexUseCase->execute($perPage, null, $filters);

        return $this->successResponse($result);
    }

    public function store(OrdenServicioRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Orden de servicio creada exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Orden de servicio no encontrada.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(OrdenServicioRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Orden de servicio no encontrada.', 404);
        }

        return $this->successResponse($result, 200, 'Orden de servicio actualizada exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Orden de servicio no encontrada.', 404);
        }

        return $this->successResponse(null, 200, 'Orden de servicio eliminada exitosamente.');
    }
}
