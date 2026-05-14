<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Etiqueta\IndexEtiquetaUseCase;
use App\Application\UseCases\Etiqueta\ShowEtiquetaUseCase;
use App\Application\UseCases\Etiqueta\StoreEtiquetaUseCase;
use App\Application\UseCases\Etiqueta\UpdateEtiquetaUseCase;
use App\Application\UseCases\Etiqueta\DestroyEtiquetaUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\EtiquetaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EtiquetaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexEtiquetaUseCase $indexUseCase,
        private ShowEtiquetaUseCase $showUseCase,
        private StoreEtiquetaUseCase $storeUseCase,
        private UpdateEtiquetaUseCase $updateUseCase,
        private DestroyEtiquetaUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(EtiquetaRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Etiqueta creada exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Etiqueta no encontrada.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(EtiquetaRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Etiqueta no encontrada.', 404);
        }

        return $this->successResponse($result, 200, 'Etiqueta actualizada exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Etiqueta no encontrada.', 404);
        }

        return $this->successResponse(null, 200, 'Etiqueta eliminada exitosamente.');
    }
}
