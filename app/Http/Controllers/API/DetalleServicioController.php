<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\DetalleServicio\IndexDetalleServicioUseCase;
use App\Application\UseCases\DetalleServicio\ShowDetalleServicioUseCase;
use App\Application\UseCases\DetalleServicio\StoreDetalleServicioUseCase;
use App\Application\UseCases\DetalleServicio\UpdateDetalleServicioUseCase;
use App\Application\UseCases\DetalleServicio\DestroyDetalleServicioUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\DetalleServicioRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DetalleServicioController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexDetalleServicioUseCase $indexUseCase,
        private ShowDetalleServicioUseCase $showUseCase,
        private StoreDetalleServicioUseCase $storeUseCase,
        private UpdateDetalleServicioUseCase $updateUseCase,
        private DestroyDetalleServicioUseCase $destroyUseCase,
    ) {}

    public function index(Request $request, int $servicioId): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $filters = array_merge(
            $request->only(['producto_id']),
            ['servicio_id' => $servicioId]
        );

        $result = $this->indexUseCase->execute($perPage, null, $filters);

        return $this->successResponse($result);
    }

    public function store(DetalleServicioRequest $request, int $servicioId): JsonResponse
    {
        $data = $request->validated();
        $data['servicio_id'] = $servicioId;

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

    public function update(DetalleServicioRequest $request, int $id): JsonResponse
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
