<?php

namespace App\Http\Controllers\API;

use App\Application\Enums\DeleteOutcome;
use App\Application\UseCases\DetalleOportunidad\DestroyDetalleOportunidadUseCase;
use App\Application\UseCases\DetalleOportunidad\IndexDetalleOportunidadUseCase;
use App\Application\UseCases\DetalleOportunidad\ShowDetalleOportunidadUseCase;
use App\Application\UseCases\DetalleOportunidad\StoreDetalleOportunidadUseCase;
use App\Application\UseCases\DetalleOportunidad\UpdateDetalleOportunidadUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\DetalleOportunidadRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        if (! $result) {
            return $this->errorResponse('Detalle no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(DetalleOportunidadRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->updateUseCase->execute($id, $request->validated());
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Detalle no encontrado.', 404);
        } catch (\Throwable $e) {
            // Surface unhandled errors (do NOT swallow). Global handler converts to 500.
            Log::error('DetalleOportunidad update failed', [
                'id' => $id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        if (! $result) {
            return $this->errorResponse('Detalle no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Detalle actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $outcome = $this->destroyUseCase->execute($id);
        } catch (\Throwable $e) {
            Log::error('DetalleOportunidad destroy failed', [
                'id' => $id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        return match ($outcome) {
            DeleteOutcome::Deleted => $this->successResponse(
                ['deleted' => true, 'id' => $id],
                200,
                'Detalle eliminado exitosamente.'
            ),
            DeleteOutcome::NotFound => $this->errorResponse('Detalle no encontrado.', 404),
            DeleteOutcome::FkBlocked => $this->errorResponse(
                'No se puede eliminar el detalle: restricción de integridad referencial.',
                422
            ),
        };
    }
}
