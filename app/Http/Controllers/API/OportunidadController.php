<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Oportunidad\IndexOportunidadUseCase;
use App\Application\UseCases\Oportunidad\ShowOportunidadUseCase;
use App\Application\UseCases\Oportunidad\StoreOportunidadUseCase;
use App\Application\UseCases\Oportunidad\UpdateOportunidadUseCase;
use App\Application\UseCases\Oportunidad\DestroyOportunidadUseCase;
use App\Application\UseCases\Oportunidad\GanarOportunidadUseCase;
use App\Application\UseCases\Oportunidad\ClonarOportunidadUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\OportunidadRequest;
use App\Http\Resources\OportunidadResource;
use App\Traits\DispatchesWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OportunidadController extends Controller
{
    use ApiResponse, DispatchesWebhooks;

    public function __construct(
        private IndexOportunidadUseCase $indexUseCase,
        private ShowOportunidadUseCase $showUseCase,
        private StoreOportunidadUseCase $storeUseCase,
        private UpdateOportunidadUseCase $updateUseCase,
        private DestroyOportunidadUseCase $destroyUseCase,
        private GanarOportunidadUseCase $ganarUseCase,
        private ClonarOportunidadUseCase $clonarUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 50), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado', 'entidad_id', 'producto_id', 'fecha_desde', 'fecha_hasta', 'codigo']);
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $result = $this->indexUseCase->execute($perPage, $search, $filters, $sortBy, $sortOrder);

        // Eager-load relationships for each item
        $result->getCollection()->transform(fn ($item) => $item->load(['entidad', 'detalles.producto']));

        $resource = OportunidadResource::collection($result);
        $serialized = $resource->toArray(request());
        $paginator = $result->toArray();

        return $this->successResponse([
            'data' => $serialized,
            'total' => $paginator['total'] ?? 0,
            'current_page' => $paginator['current_page'] ?? 1,
            'last_page' => $paginator['last_page'] ?? 1,
            'per_page' => (int) $paginator['per_page'],
        ]);
    }

    public function store(OportunidadRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        // Dispatch webhook
        $entidad = $this->getEntidadForWebhook($result);
        if ($entidad) {
            $this->dispatchWebhook($entidad, 'oportunidad.created', [
                'id' => $result->id,
                'codigo' => $result->codigo,
                'estado' => $result->estado,
                'entidad_id' => $result->entidad_id,
            ]);
        }

        return $this->successResponse($result, 201, 'Oportunidad creada exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $oportunidad = \App\Models\Oportunidad::with(['entidad', 'detalles'])->find($id);

        if (!$oportunidad) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        return $this->successResponse(new OportunidadResource($oportunidad));
    }

    public function update(OportunidadRequest $request, int $id): JsonResponse
    {
        $oldOportunidad = $this->showUseCase->execute($id);
        $oldEstado = $oldOportunidad?->estado ?? null;

        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        // Dispatch webhook
        $entidad = $this->getEntidadForWebhook($result);
        if ($entidad) {
            $event = 'oportunidad.updated';

            // Detectar cambio de estado
            if ($oldEstado && $oldEstado !== $result->estado) {
                $event = 'oportunidad.estado_changed';
            }

            $this->dispatchWebhook($entidad, $event, [
                'id' => $result->id,
                'codigo' => $result->codigo,
                'estado' => $result->estado,
                'estado_anterior' => $oldEstado,
                'entidad_id' => $result->entidad_id,
            ]);
        }

        return $this->successResponse($result, 200, 'Oportunidad actualizada exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $oldOportunidad = $this->showUseCase->execute($id);

        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        // Dispatch webhook
        if ($oldOportunidad) {
            $entidad = $this->getEntidadForWebhook($oldOportunidad);
            if ($entidad) {
                $this->dispatchWebhook($entidad, 'oportunidad.deleted', [
                    'id' => $oldOportunidad->id,
                    'codigo' => $oldOportunidad->codigo,
                    'entidad_id' => $oldOportunidad->entidad_id,
                ]);
            }
        }

        return $this->successResponse(null, 200, 'Oportunidad eliminada exitosamente.');
    }

    public function ganar(int $id): JsonResponse
    {
        $oportunidad = \App\Models\Oportunidad::find($id);

        if (!$oportunidad) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        if ($oportunidad->estado !== 'Negociada') {
            return $this->errorResponse('Solo oportunidades negociadas pueden marcarse como ganadas.', 422);
        }

        $result = $this->ganarUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        return $this->successResponse($result, 200, 'Oportunidad ganada. Servicio creado y entidad actualizada a cliente.');
    }

    public function clonar(int $id): JsonResponse
    {
        $result = $this->clonarUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        return $this->successResponse($result, 201, 'Oportunidad clonada exitosamente.');
    }
}
