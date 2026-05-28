<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Maestro\DeleteMaestroUseCase;
use App\Application\UseCases\Maestro\IndexMaestroUseCase;
use App\Application\UseCases\Maestro\ShowMaestroUseCase;
use App\Application\UseCases\Maestro\StoreMaestroUseCase;
use App\Application\UseCases\Maestro\UpdateMaestroUseCase;
use App\Infrastructure\Services\ActividadLogger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\MaestroRequest;
use App\Http\Resources\MaestroResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MaestroController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexMaestroUseCase $indexUseCase,
        private ShowMaestroUseCase $showUseCase,
        private StoreMaestroUseCase $storeUseCase,
        private UpdateMaestroUseCase $updateUseCase,
        private DeleteMaestroUseCase $deleteUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 50), 100);
        $search = $request->input('search');
        $filters = $request->only(['campo']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(MaestroRequest $request): JsonResponse
    {
        $maestro = $this->storeUseCase->execute($request->validated());

        ActividadLogger::created(
            Auth::id(),
            "Maestro creado: {$maestro->nombre} (campo: {$maestro->campo})",
            'Maestro',
            $maestro->id,
        );

        return $this->successResponse(
            new MaestroResource($maestro),
            201,
            'Maestro creado exitosamente.',
        );
    }

    public function show(int $id): JsonResponse
    {
        $maestro = $this->showUseCase->execute($id);

        if (!$maestro) {
            return $this->errorResponse('Maestro no encontrado.', 404);
        }

        return $this->successResponse(new MaestroResource($maestro));
    }

    public function update(int $id, MaestroRequest $request): JsonResponse
    {
        $maestro = $this->updateUseCase->execute($id, $request->validated());

        if (!$maestro) {
            return $this->errorResponse('Maestro no encontrado.', 404);
        }

        ActividadLogger::updated(
            Auth::id(),
            "Maestro actualizado: {$maestro->nombre} (campo: {$maestro->campo})",
            'Maestro',
            $maestro->id,
        );

        return $this->successResponse(
            new MaestroResource($maestro),
            200,
            'Maestro actualizado exitosamente.',
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->deleteUseCase->execute($id);

        if (!$deleted) {
            return $this->errorResponse('Maestro no encontrado.', 404);
        }

        ActividadLogger::deleted(Auth::id(), "Maestro eliminado (ID: {$id})", 'Maestro', $id);

        return $this->successResponse(null, 200, 'Maestro eliminado exitosamente.');
    }
}
