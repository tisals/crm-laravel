<?php

namespace Modules\CRM\Http\Controllers;

use App\Application\UseCases\App\AssignAppToEntidadUseCase;
use App\Application\UseCases\App\DestroyAppUseCase;
use App\Application\UseCases\App\IndexAppUseCase;
use App\Application\UseCases\App\ListAppsByEntidadUseCase;
use App\Application\UseCases\App\ListEntidadesByAppUseCase;
use App\Application\UseCases\App\RemoveAppFromEntidadUseCase;
use App\Application\UseCases\App\ShowAppUseCase;
use App\Application\UseCases\App\StoreAppUseCase;
use App\Application\UseCases\App\UpdateAppUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppRequest;
use App\Http\Resources\AppResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppController extends Controller
{
    use ApiResponse;

    public function __construct(
        private IndexAppUseCase $indexUseCase,
        private ShowAppUseCase $showUseCase,
        private StoreAppUseCase $storeUseCase,
        private UpdateAppUseCase $updateUseCase,
        private DestroyAppUseCase $destroyUseCase,
        private ListAppsByEntidadUseCase $listAppsByEntidadUseCase,
        private ListEntidadesByAppUseCase $listEntidadesByAppUseCase,
        private AssignAppToEntidadUseCase $assignUseCase,
        private RemoveAppFromEntidadUseCase $removeUseCase,
    ) {}

    // ── App catalog CRUD ─────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 25), 100);
        $search = $request->input('search');
        $filters = $request->only(['tipo', 'activo']);

        $result = $this->indexUseCase->execute($perPage, $search, $filters);

        return $this->successResponse([
            'data' => AppResource::collection($result->items()),
            'total' => $result->total(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
        ]);
    }

    public function store(AppRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse(
            new AppResource($result),
            201,
            'App creada exitosamente.'
        );
    }

    public function show(int $id): JsonResponse
    {
        $result = $this->showUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('App no encontrada.', 404);
        }

        return $this->successResponse(new AppResource($result));
    }

    public function update(AppRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (! $result) {
            return $this->errorResponse('App no encontrada.', 404);
        }

        return $this->successResponse(
            new AppResource($result),
            200,
            'App actualizada exitosamente.'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->destroyUseCase->execute($id);

        if (! $result) {
            return $this->errorResponse('App no encontrada.', 404);
        }

        return $this->successResponse(null, 200, 'App eliminada exitosamente.');
    }

    // ── App ↔ Entidad assignments ────────────────────

    /**
     * GET /api/v1/entidad/{entidadId}/apps
     * Lists all apps currently assigned to the given entity.
     */
    public function appsByEntidad(int $entidadId): JsonResponse
    {
        $apps = $this->listAppsByEntidadUseCase->execute($entidadId);

        return $this->successResponse($apps);
    }

    /**
     * GET /api/v1/apps/{appId}/entidades
     * Lists all entities that have the given app.
     */
    public function entidadesByApp(int $appId): JsonResponse
    {
        $entidades = $this->listEntidadesByAppUseCase->execute($appId);

        return $this->successResponse($entidades);
    }

    /**
     * POST /api/v1/entidad/{entidadId}/apps/{appId}
     * Assigns an app to an entity. Idempotent.
     */
    public function assignAppToEntidad(Request $request, int $entidadId, int $appId): JsonResponse
    {
        $validated = $request->validate([
            'fecha_contrato' => 'nullable|date',
            'fecha_vencimiento' => 'nullable|date',
            'estado' => 'nullable|in:Activo,Suspendido,Cancelado,Trial',
            'notas' => 'nullable|string|max:1000',
        ]);

        $pivotId = $this->assignUseCase->execute(
            appId: $appId,
            entidadId: $entidadId,
            metadata: array_merge($validated, ['created_by' => $request->user()?->id])
        );

        return $this->successResponse(
            ['pivot_id' => $pivotId],
            200,
            'App asignada a la entidad.'
        );
    }

    /**
     * DELETE /api/v1/entidad/{entidadId}/apps/{appId}
     * Removes the app↔entidad assignment. Idempotent.
     */
    public function removeAppFromEntidad(int $entidadId, int $appId): JsonResponse
    {
        $removed = $this->removeUseCase->execute($appId, $entidadId);

        return $this->successResponse(
            ['removed' => $removed],
            200,
            'App removida de la entidad.'
        );
    }
}
