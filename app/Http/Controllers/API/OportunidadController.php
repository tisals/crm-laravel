<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Oportunidad\ClonarOportunidadUseCase;
use App\Application\UseCases\Oportunidad\CrearVersionOportunidadUseCase;
use App\Application\UseCases\Oportunidad\DestroyOportunidadUseCase;
use App\Application\UseCases\Oportunidad\GanarOportunidadUseCase;
use App\Application\UseCases\Oportunidad\IndexOportunidadUseCase;
use App\Application\UseCases\Oportunidad\ShowOportunidadUseCase;
use App\Application\UseCases\Oportunidad\StoreOportunidadUseCase;
use App\Application\UseCases\Oportunidad\UpdateOportunidadUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\OportunidadRequest;
use App\Http\Resources\OportunidadResource;
use App\Models\Oportunidad;
use App\Traits\DispatchesWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Models\PipelineEtapa;

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
        private CrearVersionOportunidadUseCase $versionarUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 50), 100);
        $search = $request->input('search');
        $filters = $request->only(['pipeline_id', 'estado', 'entidad_id', 'producto_id', 'fecha_desde', 'fecha_hasta', 'codigo']);

        // is_latest is special: it's a boolean, not a string. Default true.
        // ?is_latest=false returns ALL versions (for history views in detail panel).
        $filters['is_latest'] = $request->has('is_latest')
            ? filter_var($request->input('is_latest'), FILTER_VALIDATE_BOOLEAN)
            : true;

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

        $model = Oportunidad::with(['entidad', 'detalles'])->find($result->id);

        return $this->successResponse(new OportunidadResource($model), 201, 'Oportunidad creada exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $oportunidad = Oportunidad::with(['entidad', 'detalles'])->find($id);

        if (! $oportunidad) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        return $this->successResponse(new OportunidadResource($oportunidad));
    }

    public function update(OportunidadRequest $request, int $id): JsonResponse
    {
        $oldOportunidad = $this->showUseCase->execute($id);
        $oldEstado = $oldOportunidad?->estado ?? null;

        $result = $this->updateUseCase->execute($id, $request->validated());

        if (! $result) {
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

        $model = Oportunidad::with(['entidad', 'detalles'])->find($id);

        return $this->successResponse(new OportunidadResource($model), 200, 'Oportunidad actualizada exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $oldOportunidad = $this->showUseCase->execute($id);

        $result = $this->destroyUseCase->execute($id);

        if (! $result) {
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
        $oportunidad = Oportunidad::find($id);

        if (! $oportunidad) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        $etapaNombre = $oportunidad->pipelineEtapa?->nombre ?? PipelineEtapa::find($oportunidad->pipeline_etapa_id)?->nombre;
        if ($etapaNombre !== 'Aceptada') {
            return $this->errorResponse('Solo oportunidades aceptadas pueden marcarse como ganadas.', 422);
        }

        $result = $this->ganarUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        return $this->successResponse($result, 200, 'Oportunidad ganada. Servicio creado y entidad actualizada a cliente.');
    }

    public function clonar(int $id): JsonResponse
    {
        $result = $this->clonarUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        return $this->successResponse($result, 201, 'Oportunidad clonada exitosamente.');
    }

    public function versionar(int $id): JsonResponse
    {
        $result = $this->versionarUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('Oportunidad no encontrada.', 404);
        }

        return $this->successResponse(new OportunidadResource($result), 201, 'Nueva versión de la oportunidad creada exitosamente.');
    }
}
