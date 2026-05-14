<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Servicio\IndexServicioUseCase;
use App\Application\UseCases\Servicio\ShowServicioUseCase;
use App\Application\UseCases\Servicio\StoreServicioUseCase;
use App\Application\UseCases\Servicio\UpdateServicioUseCase;
use App\Application\UseCases\Servicio\DestroyServicioUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\ServicioRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicioController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexServicioUseCase $indexUseCase,
        private ShowServicioUseCase $showUseCase,
        private StoreServicioUseCase $storeUseCase,
        private UpdateServicioUseCase $updateUseCase,
        private DestroyServicioUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado', 'entidad_id']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(ServicioRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Servicio creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Servicio no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(ServicioRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Servicio no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Servicio actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Servicio no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Servicio eliminado exitosamente.');
    }
}
