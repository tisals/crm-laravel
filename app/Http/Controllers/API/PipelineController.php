<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use Modules\CRM\Models\Pipeline;
use Illuminate\Http\JsonResponse;

class PipelineController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $pipelines = Pipeline::with(['etapas' => function ($q) {
            $q->where('habilitado', true)->orderBy('orden');
        }])->where('habilitado', true)->get();

        return $this->successResponse($pipelines);
    }
}
