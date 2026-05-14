<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Usuario\IndexUsuarioUseCase;
use App\Application\UseCases\Usuario\ShowUsuarioUseCase;
use App\Application\UseCases\Usuario\StoreUsuarioUseCase;
use App\Application\UseCases\Usuario\UpdateUsuarioUseCase;
use App\Application\UseCases\Usuario\DestroyUsuarioUseCase;
use App\Application\UseCases\Usuario\ToggleStatusUsuarioUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\UsuarioRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexUsuarioUseCase $indexUseCase,
        private ShowUsuarioUseCase $showUseCase,
        private StoreUsuarioUseCase $storeUseCase,
        private UpdateUsuarioUseCase $updateUseCase,
        private DestroyUsuarioUseCase $destroyUseCase,
        private ToggleStatusUsuarioUseCase $toggleStatusUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['estado', 'rol_id']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(UsuarioRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Usuario creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Usuario no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(UsuarioRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Usuario no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Usuario actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $result = $this->destroyUseCase->execute($id, auth()->id());

            if (!$result) {
                return $this->errorResponse('Usuario no encontrado.', 404);
            }

            return $this->successResponse(null, 200, 'Usuario eliminado exitosamente.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $result = $this->toggleStatusUseCase->execute($id);

        return $this->successResponse($result, 200, 'Estado actualizado exitosamente.');
    }
}
