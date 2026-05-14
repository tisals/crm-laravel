<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Cuenta\IndexCuentaUseCase;
use App\Application\UseCases\Cuenta\ShowCuentaUseCase;
use App\Application\UseCases\Cuenta\StoreCuentaUseCase;
use App\Application\UseCases\Cuenta\UpdateCuentaUseCase;
use App\Application\UseCases\Cuenta\DestroyCuentaUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\CuentaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CuentaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexCuentaUseCase $indexUseCase,
        private ShowCuentaUseCase $showUseCase,
        private StoreCuentaUseCase $storeUseCase,
        private UpdateCuentaUseCase $updateUseCase,
        private DestroyCuentaUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $filters = $request->only(['proveedor_id', 'estado']);

        $result = $this->indexUseCase->execute($perPage, null, $filters);

        return $this->successResponse($result);
    }

    public function store(CuentaRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Cuenta creada exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Cuenta no encontrada.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(CuentaRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Cuenta no encontrada.', 404);
        }

        return $this->successResponse($result, 200, 'Cuenta actualizada exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Cuenta no encontrada.', 404);
        }

        return $this->successResponse(null, 200, 'Cuenta eliminada exitosamente.');
    }
}
