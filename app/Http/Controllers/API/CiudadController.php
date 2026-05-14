<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Ciudad\IndexCiudadUseCase;
use App\Application\UseCases\Ciudad\ShowCiudadUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CiudadController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexCiudadUseCase $indexUseCase,
        private ShowCiudadUseCase $showUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['departamento']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function show(string $codMunicipio): JsonResponse
    {
        $result = $this->showUseCase->execute($codMunicipio);

        if (!$result) {
            return $this->errorResponse('Ciudad no encontrada.', 404);
        }

        return $this->successResponse($result);
    }
}
