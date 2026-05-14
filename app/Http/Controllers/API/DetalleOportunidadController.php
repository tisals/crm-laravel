<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\DetalleOportunidad\IndexDetalleOportunidadUseCase;
use App\Application\UseCases\DetalleOportunidad\ShowDetalleOportunidadUseCase;
use App\Application\UseCases\DetalleOportunidad\StoreDetalleOportunidadUseCase;
use App\Application\UseCases\DetalleOportunidad\UpdateDetalleOportunidadUseCase;
use App\Application\UseCases\DetalleOportunidad\DestroyDetalleOportunidadUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\DetalleOportunidadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DetalleOportunidadController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexDetalleOportunidadUseCase $indexUseCase,
        private ShowDetalleOportunidadUseCase $showUseCase,
        private StoreDetalleOportunidadUseCase $storeUseCase,
        private UpdateDetalleOportunidadUseCase $updateUseCase,
        private DestroyDetalleOportunidadUseCase $destroyUseCase,
    ) {}

    public function index(Request $request, int $oportunidadId): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $filters = array_merge(
            $request->only(['producto_id']),
            ['oportunidad_id' => $oportunidadId]
        );

        $result = $this->indexUseCase->execute($perPage, null, $filters);

        return $this->successResponse($result);
    }

    public function store(DetalleOportunidadRequest $request, int $oportunidadId): JsonResponse
    {
        $data = $request->validated();
        $data['oportunidad_id'] = $oportunidadId;

        $result = $this->storeUseCase->execute($data);

        return $this->successResponse($result, 201, 'Detalle creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Detalle no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(DetalleOportunidadRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Detalle no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Detalle actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Detalle no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Detalle eliminado exitosamente.');
    }
}
