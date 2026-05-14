<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\LugarEntidad\IndexLugarEntidadUseCase;
use App\Application\UseCases\LugarEntidad\ShowLugarEntidadUseCase;
use App\Application\UseCases\LugarEntidad\StoreLugarEntidadUseCase;
use App\Application\UseCases\LugarEntidad\UpdateLugarEntidadUseCase;
use App\Application\UseCases\LugarEntidad\DestroyLugarEntidadUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\LugarEntidadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LugarEntidadController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexLugarEntidadUseCase $indexUseCase,
        private ShowLugarEntidadUseCase $showUseCase,
        private StoreLugarEntidadUseCase $storeUseCase,
        private UpdateLugarEntidadUseCase $updateUseCase,
        private DestroyLugarEntidadUseCase $destroyUseCase,
    ) {}

    public function index(Request $request, int $entidadId): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = array_merge($request->only(['estado']), ['entidad_id' => $entidadId]);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(LugarEntidadRequest $request, int $entidadId): JsonResponse
    {
        $data = array_merge($request->validated(), ['entidad_id' => $entidadId]);
        $result = $this->storeUseCase->execute($data);

        return $this->successResponse($result, 201, 'Lugar creado exitosamente.');
    }

    public function show(int $entidadId, int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Lugar no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(LugarEntidadRequest $request, int $entidadId, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Lugar no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Lugar actualizado exitosamente.');
    }

    public function destroy(int $entidadId, int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Lugar no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Lugar eliminado exitosamente.');
    }
}
