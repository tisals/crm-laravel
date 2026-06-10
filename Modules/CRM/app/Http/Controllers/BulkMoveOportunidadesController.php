<?php

namespace Modules\CRM\Http\Controllers;

use App\Application\UseCases\Oportunidad\BulkMoveOportunidadesToPipelineUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkMoveOportunidadesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class BulkMoveOportunidadesController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BulkMoveOportunidadesToPipelineUseCase $useCase,
    ) {}

    /**
     * Handle the bulk move of oportunidades to a target pipeline etapa.
     */
    public function __invoke(BulkMoveOportunidadesRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $this->useCase->execute(
                $data['oportunidad_ids'],
                $data['target_pipeline_etapa_id'],
            );

            return $this->successResponse($result);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['invalid_ids'])) {
                $invalidIds = array_map('intval', $errors['invalid_ids']);

                return response()->json([
                    'success' => false,
                    'invalid_ids' => $invalidIds,
                ], 422);
            }

            throw $e;
        }
    }
}
