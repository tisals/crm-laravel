<?php

namespace Modules\CRM\Http\Controllers;

use App\Application\UseCases\Contacto\DestroyContactoUseCase;
use App\Application\UseCases\Contacto\IndexContactoUseCase;
use App\Application\UseCases\Contacto\ReasignarContactoUseCase;
use App\Application\UseCases\Contacto\ShowContactoUseCase;
use App\Application\UseCases\Contacto\StoreContactoUseCase;
use App\Application\UseCases\Contacto\UpdateContactoUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
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

        if (! $result) {
            return $this->errorResponse('Contacto no encontrado.', 404);
        }

        return $this->successResponse($result);
    }

    public function update(ContactoRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (! $result) {
            return $this->errorResponse('Contacto no encontrado.', 404);
        }

        return $this->successResponse($result, 200, 'Contacto actualizado exitosamente.');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('Contacto no encontrado.', 404);
        }

        return $this->successResponse(null, 200, 'Contacto eliminado exitosamente.');
    }

    /**
     * POST /api/v1/contacto/{id}/reasignar
     * Reasigna un contacto a otra entidad. Si hay conflicto de email,
     * retorna 409 con info del contacto existente. Si merge=true, fusiona.
     */
    public function reasignar(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'entidad_id' => 'required|integer|exists:entidad,id',
            'merge' => 'sometimes|boolean',
        ]);

        $useCase = new ReasignarContactoUseCase();
        $result = $useCase->execute($id, $validated['entidad_id'], $validated['merge'] ?? false);

        if (! $result['success'] && isset($result['conflict'])) {
            return response()->json([
                'success' => false,
                'error' => 'conflict',
                'conflicting_contacto' => $result['conflict'],
                'message' => $result['message'],
            ], 409);
        }

        if (! $result['success']) {
            return $this->errorResponse($result['message'], 404);
        }

        return $this->successResponse($result['data'], 200, $result['message']);
    }
}
