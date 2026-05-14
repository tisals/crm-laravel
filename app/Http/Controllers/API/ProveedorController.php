<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Proveedor\IndexProveedorUseCase;
use App\Application\UseCases\Proveedor\ShowProveedorUseCase;
use App\Application\UseCases\Proveedor\StoreProveedorUseCase;
use App\Application\UseCases\Proveedor\UpdateProveedorUseCase;
use App\Application\UseCases\Proveedor\DestroyProveedorUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\ProveedorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProveedorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexProveedorUseCase $indexUseCase,
        private ShowProveedorUseCase $showUseCase,
        private StoreProveedorUseCase $storeUseCase,
        private UpdateProveedorUseCase $updateUseCase,
        private DestroyProveedorUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(ProveedorRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Proveedor creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Proveedor no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(ProveedorRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Proveedor no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Proveedor actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Proveedor no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Proveedor eliminado exitosamente.');
    }
}
