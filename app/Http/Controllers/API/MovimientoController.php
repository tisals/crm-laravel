<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Movimiento\IndexMovimientoUseCase;
use App\Application\UseCases\Movimiento\ShowMovimientoUseCase;
use App\Application\UseCases\Movimiento\StoreMovimientoUseCase;
use App\Application\UseCases\Movimiento\UpdateMovimientoUseCase;
use App\Application\UseCases\Movimiento\DestroyMovimientoUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\MovimientoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MovimientoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexMovimientoUseCase $indexUseCase,
        private ShowMovimientoUseCase $showUseCase,
        private StoreMovimientoUseCase $storeUseCase,
        private UpdateMovimientoUseCase $updateUseCase,
        private DestroyMovimientoUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $filters = $request->only(['proveedor_id', 'colaborador_id', 'servicio_id']);

        $result = $this->indexUseCase->execute($perPage, null, $filters);

        return $this->successResponse($result);
    }

    public function store(MovimientoRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Movimiento creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Movimiento no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(MovimientoRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Movimiento no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Movimiento actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Movimiento no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Movimiento eliminado exitosamente.');
    }
}
