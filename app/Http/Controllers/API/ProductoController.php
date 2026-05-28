<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Producto\IndexProductoUseCase;
use App\Application\UseCases\Producto\ShowProductoUseCase;
use App\Application\UseCases\Producto\StoreProductoUseCase;
use App\Application\UseCases\Producto\UpdateProductoUseCase;
use App\Application\UseCases\Producto\DestroyProductoUseCase;
use App\Infrastructure\Services\ActividadLogger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\ProductoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexProductoUseCase $indexUseCase,
        private ShowProductoUseCase $showUseCase,
        private StoreProductoUseCase $storeUseCase,
        private UpdateProductoUseCase $updateUseCase,
        private DestroyProductoUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado', 'linea_negocio']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(ProductoRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        ActividadLogger::created(
            Auth::id(),
            "Producto creado: {$result->nombre}",
            'Producto',
            $result->id,
        );

        return $this->successResponse($result, 201, 'Producto creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Producto no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(ProductoRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Producto no encontrado.', 404);
        }

        ActividadLogger::updated(
            Auth::id(),
            "Producto actualizado: {$result->nombre}",
            'Producto',
            $id,
        );

        return $this->successResponse($result, 200, 'Producto actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Producto no encontrado.', 404);
        }

        ActividadLogger::deleted(Auth::id(), "Producto eliminado (ID: {$id})", 'Producto', $id);

        return $this->successResponse(null, 200, 'Producto eliminado exitosamente.');
    }
}
