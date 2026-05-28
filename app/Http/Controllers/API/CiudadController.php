<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Ciudad\DeleteCiudadUseCase;
use App\Application\UseCases\Ciudad\IndexCiudadUseCase;
use App\Application\UseCases\Ciudad\ShowCiudadUseCase;
use App\Application\UseCases\Ciudad\StoreCiudadUseCase;
use App\Application\UseCases\Ciudad\UpdateCiudadUseCase;
use App\Infrastructure\Services\ActividadLogger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\CiudadRequest;
use App\Http\Resources\CiudadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CiudadController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexCiudadUseCase $indexUseCase,
        private ShowCiudadUseCase $showUseCase,
        private StoreCiudadUseCase $storeUseCase,
        private UpdateCiudadUseCase $updateUseCase,
        private DeleteCiudadUseCase $deleteUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 50), 100);
        $search = $request->input('search');
        $filters = $request->only(['departamento']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(CiudadRequest $request): JsonResponse
    {
        $ciudad = $this->storeUseCase->execute($request->validated());

        ActividadLogger::created(
            Auth::id(),
            "Ciudad creada: {$ciudad->nombre} ({$ciudad->codMunicipio})",
            'Ciudad',
        );

        return $this->successResponse(
            new CiudadResource($ciudad),
            201,
            'Ciudad creada exitosamente.',
        );
    }

    public function show(string $codMunicipio): JsonResponse
    {
        $result = $this->showUseCase->execute($codMunicipio);

        if (!$result) {
            return $this->errorResponse('Ciudad no encontrada.', 404);
        }

        return $this->successResponse(new CiudadResource($result));
    }

    public function update(string $codMunicipio, CiudadRequest $request): JsonResponse
    {
        $ciudad = $this->updateUseCase->execute($codMunicipio, $request->validated());

        if (!$ciudad) {
            return $this->errorResponse('Ciudad no encontrada.', 404);
        }

        ActividadLogger::updated(
            Auth::id(),
            "Ciudad actualizada: {$ciudad->nombre} ({$codMunicipio})",
            'Ciudad',
        );

        return $this->successResponse(
            new CiudadResource($ciudad),
            200,
            'Ciudad actualizada exitosamente.',
        );
    }

    public function destroy(string $codMunicipio): JsonResponse
    {
        $deleted = $this->deleteUseCase->execute($codMunicipio);

        if (!$deleted) {
            return $this->errorResponse('Ciudad no encontrada.', 404);
        }

        ActividadLogger::deleted(Auth::id(), "Ciudad eliminada: {$codMunicipio}", 'Ciudad');

        return $this->successResponse(null, 200, 'Ciudad eliminada exitosamente.');
    }
}
