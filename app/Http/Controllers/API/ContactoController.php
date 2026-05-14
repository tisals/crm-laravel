<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Contacto\IndexContactoUseCase;
use App\Application\UseCases\Contacto\ShowContactoUseCase;
use App\Application\UseCases\Contacto\StoreContactoUseCase;
use App\Application\UseCases\Contacto\UpdateContactoUseCase;
use App\Application\UseCases\Contacto\DestroyContactoUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Requests\ContactoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexContactoUseCase $indexUseCase,
        private ShowContactoUseCase $showUseCase,
        private StoreContactoUseCase $storeUseCase,
        private UpdateContactoUseCase $updateUseCase,
        private DestroyContactoUseCase $destroyUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 15), 100);
        $search = $request->input('search');
        $filters = $request->only(['entidad_id', 'estado']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse($result);
    }

    public function store(ContactoRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse($result, 201, 'Contacto creado exitosamente.');
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Contacto no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(ContactoRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (!$result) {
            return $this->errorResponse('Contacto no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Contacto actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (!$result) {
            return $this->errorResponse('Contacto no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Contacto eliminado exitosamente.');
    }
}
