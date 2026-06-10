<?php

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Application\UseCases\PipelineEtapa\CreateEtapaUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\DeleteEtapaUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\GetEtapaUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\ListEtapasUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\ReorderEtapasUseCase;
use Modules\CRM\Application\UseCases\PipelineEtapa\UpdateEtapaUseCase;
use Modules\CRM\Http\Requests\ReorderEtapasRequest;
use Modules\CRM\Http\Requests\StoreEtapaRequest;
use Modules\CRM\Http\Requests\UpdateEtapaRequest;
use Modules\CRM\Http\Resources\PipelineEtapaResource;

class PipelineEtapaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ListEtapasUseCase $listUseCase,
        private GetEtapaUseCase $getUseCase,
        private CreateEtapaUseCase $createUseCase,
        private UpdateEtapaUseCase $updateUseCase,
        private DeleteEtapaUseCase $deleteUseCase,
        private ReorderEtapasUseCase $reorderUseCase,
    ) {}

    public function index(int $pipelineId): JsonResponse
    {
        $etapas = $this->listUseCase->execute($pipelineId);

        return $this->successResponse(PipelineEtapaResource::collection($etapas));
    }

    public function store(int $pipelineId, StoreEtapaRequest $request): JsonResponse
    {
        $data = array_merge(
            $request->validated(),
            ['pipeline_id' => $pipelineId],
        );

        $etapa = $this->createUseCase->execute($data);

        return $this->successResponse(
            new PipelineEtapaResource($etapa),
            201,
            'Etapa creada exitosamente.',
        );
    }

    public function show(int $id): JsonResponse
    {
        $etapa = $this->getUseCase->execute($id);

        return $this->successResponse(new PipelineEtapaResource($etapa));
    }

    public function update(int $id, UpdateEtapaRequest $request): JsonResponse
    {
        $etapa = $this->updateUseCase->execute($id, $request->validated());

        return $this->successResponse(
            new PipelineEtapaResource($etapa),
            200,
            'Etapa actualizada exitosamente.',
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->deleteUseCase->execute($id);

        return $this->successResponse(null, 200, 'Etapa eliminada exitosamente.');
    }

    public function reorder(int $pipelineId, ReorderEtapasRequest $request): JsonResponse
    {
        try {
            $this->reorderUseCase->execute($pipelineId, $request->input('ordered_ids'));

            return $this->successResponse(null, 200, 'Etapas reordenadas exitosamente.');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
