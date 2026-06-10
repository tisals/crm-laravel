<?php

namespace Modules\CRM\Http\Controllers;

use App\Application\UseCases\Pipeline\CreatePipelineUseCase;
use App\Application\UseCases\Pipeline\DeletePipelineUseCase;
use App\Application\UseCases\Pipeline\GetPipelineUseCase;
use App\Application\UseCases\Pipeline\ListPipelinesUseCase;
use App\Application\UseCases\Pipeline\UpdatePipelineUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePipelineRequest;
use App\Http\Requests\UpdatePipelineRequest;
use App\Http\Resources\PipelineResource;
use Illuminate\Http\JsonResponse;

class PipelineController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ListPipelinesUseCase $listUseCase,
        private GetPipelineUseCase $getUseCase,
        private CreatePipelineUseCase $createUseCase,
        private UpdatePipelineUseCase $updateUseCase,
        private DeletePipelineUseCase $deleteUseCase,
    ) {}

    public function index(): JsonResponse
    {
        $pipelines = $this->listUseCase->execute();

        return $this->successResponse(PipelineResource::collection($pipelines));
    }

    public function store(StorePipelineRequest $request): JsonResponse
    {
        $pipeline = $this->createUseCase->execute($request->validated());

        return $this->successResponse(
            new PipelineResource($pipeline),
            201,
            'Pipeline creado exitosamente.',
        );
    }

    public function show(int $id): JsonResponse
    {
        $pipeline = $this->getUseCase->execute($id);

        return $this->successResponse(new PipelineResource($pipeline));
    }

    public function update(int $id, UpdatePipelineRequest $request): JsonResponse
    {
        $pipeline = $this->updateUseCase->execute($id, $request->validated());

        return $this->successResponse(
            new PipelineResource($pipeline),
            200,
            'Pipeline actualizado exitosamente.',
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->deleteUseCase->execute($id);

        return $this->successResponse(null, 200, 'Pipeline eliminado exitosamente.');
    }
}
