<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Colaborador\IndexColaboradorUseCase;
use App\Application\UseCases\Colaborador\ShowColaboradorUseCase;
use App\Application\UseCases\Colaborador\StoreColaboradorUseCase;
use App\Application\UseCases\Colaborador\UpdateColaboradorUseCase;
use App\Application\UseCases\Colaborador\DestroyColaboradorUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\ColaboradorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColaboradorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexColaboradorUseCase $indexUseCase,
        private ShowColaboradorUseCase $showUseCase,
        private StoreColaboradorUseCase $storeUseCase,
        private UpdateColaboradorUseCase $updateUseCase,
        private DestroyColaboradorUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(ColaboradorRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Colaborador creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Colaborador no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(ColaboradorRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Colaborador no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Colaborador actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Colaborador no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Colaborador eliminado exitosamente.');
    }
}
