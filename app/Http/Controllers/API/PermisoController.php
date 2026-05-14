<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Permiso\IndexPermisoUseCase;
use App\Application\UseCases\Permiso\ShowPermisoUseCase;
use App\Application\UseCases\Permiso\StorePermisoUseCase;
use App\Application\UseCases\Permiso\UpdatePermisoUseCase;
use App\Application\UseCases\Permiso\DestroyPermisoUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\PermisoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermisoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexPermisoUseCase $indexUseCase,
        private ShowPermisoUseCase $showUseCase,
        private StorePermisoUseCase $storeUseCase,
        private UpdatePermisoUseCase $updateUseCase,
        private DestroyPermisoUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['rol_id']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(PermisoRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Permiso creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Permiso no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(PermisoRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Permiso no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Permiso actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Permiso no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Permiso eliminado exitosamente.');
    }
}
