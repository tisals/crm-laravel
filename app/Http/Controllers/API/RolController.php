<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Rol\IndexRolUseCase;
use App\Application\UseCases\Rol\ShowRolUseCase;
use App\Application\UseCases\Rol\StoreRolUseCase;
use App\Application\UseCases\Rol\UpdateRolUseCase;
use App\Application\UseCases\Rol\DestroyRolUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\RolRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexRolUseCase $indexUseCase,
        private ShowRolUseCase $showUseCase,
        private StoreRolUseCase $storeUseCase,
        private UpdateRolUseCase $updateUseCase,
        private DestroyRolUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(RolRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Rol creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Rol no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(RolRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Rol no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Rol actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Rol no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Rol eliminado exitosamente.');
    }
}
