<?php

namespace Modules\CRM\Http\Controllers;

use App\Application\UseCases\Persona\DestroyPersonaUseCase;
use App\Application\UseCases\Persona\IndexPersonaUseCase;
use App\Application\UseCases\Persona\ShowPersonaUseCase;
use App\Application\UseCases\Persona\StorePersonaUseCase;
use App\Application\UseCases\Persona\UpdatePersonaUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PersonaRequest;
use App\Http\Resources\PersonaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexPersonaUseCase $indexUseCase,
        private ShowPersonaUseCase $showUseCase,
        private StorePersonaUseCase $storeUseCase,
        private UpdatePersonaUseCase $updateUseCase,
        private DestroyPersonaUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['ciudad', 'pais']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse([
            'data' => PersonaResource::collection($result->items()),
            'total' => $result->total(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
        ]);
    }

    public function store(PersonaRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse(
            new PersonaResource($result),
            201,
            'Persona creada exitosamente.'
        );
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('Persona no encontrada.', 404);
        }

        return $this->successResponse(new PersonaResource($result));
    }

    public function update(PersonaRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (! $result) {
            return $this->errorResponse('Persona no encontrada.', 404);
        }

        return $this->successResponse(
            new PersonaResource($result),
            200,
            'Persona actualizada exitosamente.'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('Persona no encontrada.', 404);
        }

        return $this->successResponse(null, 200, 'Persona eliminada exitosamente.');
    }
}
