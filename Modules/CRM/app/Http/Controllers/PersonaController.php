<?php

namespace Modules\CRM\Http\Controllers;

use App\Application\Auth\PersonaLookupResult;
use App\Application\UseCases\Persona\DestroyPersonaUseCase;
use App\Application\UseCases\Persona\IndexPersonaUseCase;
use App\Application\UseCases\Persona\ShowPersonaUseCase;
use App\Application\UseCases\Persona\StorePersonaUseCase;
use App\Application\UseCases\Persona\UpdatePersonaUseCase;
use App\Enums\ProjectionLevel;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PersonaStoreRequest;
use App\Http\Requests\PersonaUpdateRequest;
use App\Http\Resources\PersonaResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PR-I — persona REST surface.
 *
 * Routes resolve under `/api/v1/personas`. Each method stays thin: the
 * heavy work (validation, tenant check, persistence, event dispatch) is
 * pushed into the Form Requests and the `App\Application\UseCases\Persona\*`
 * layer. The `PersonaController` only translates HTTP ↔ use case.
 *
 * The single-class `App\Http\Requests\PersonaRequest` was split into
 * `PersonaStoreRequest` (full) and `PersonaUpdateRequest` (partial, all
 * rules `sometimes`) so PATCH can actually be partial (REQ-PRAPI-004).
 *
 * The `PersonaShow*` flow returns three states: FOUND / NOT_FOUND /
 * FORBIDDEN. The 403 path is the security-relevant one (REQ-PRAPI-003 +
 * R-10): a non-admin user requesting a persona owned by a different
 * entidad sees a tenant-isolation refusal, never a payload.
 */
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
        $perPage = min((int) $request->input('per_page', 15), 100);
        $result = $this->indexUseCase->execute($perPage, $request->input('search'), $request->only(['ciudad', 'pais']));

        return $this->successResponse([
            'data' => PersonaResource::collection($result->items()),
            'total' => $result->total(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
        ]);
    }

    /**
     * POST /api/v1/personas
     * Full validation via PersonaStoreRequest.
     */
    public function store(PersonaStoreRequest $request): JsonResponse
    {
        $result = $this->storeUseCase->execute($request->validated());

        return $this->successResponse(new PersonaResource($result), 201, 'Persona creada exitosamente.');
    }

    /**
     * GET /api/v1/personas/{id}
     * Tenant-aware: returns 404 if missing, 403 if cross-entidad + non-admin,
     * 200 if visible.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $entidadId = $user?->entidades()->first()?->id;

        $result = $this->showUseCase->execute(
            $id,
            (int) $user->id,
            $entidadId !== null ? (int) $entidadId : null,
        );

        if ($result->status === PersonaLookupResult::STATUS_FORBIDDEN) {
            return $this->errorResponse('Persona no pertenece a su entidad.', 403);
        }

        if ($result->status === PersonaLookupResult::STATUS_NOT_FOUND) {
            return $this->errorResponse('Persona no encontrada.', 404);
        }

        return $this->successResponse(new PersonaResource($result->persona));
    }

    /**
     * PATCH /api/v1/personas/{id}
     * Partial update via PersonaUpdateRequest (all rules `sometimes`).
     */
    public function update(PersonaUpdateRequest $request, int $id): JsonResponse
    {
        $result = $this->updateUseCase->execute($id, $request->validated());

        if (! $result) {
            return $this->errorResponse('Persona no encontrada.', 404);
        }

        return $this->successResponse(new PersonaResource($result), 200, 'Persona actualizada exitosamente.');
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
