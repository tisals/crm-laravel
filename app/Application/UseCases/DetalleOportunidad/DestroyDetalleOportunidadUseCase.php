<?php

namespace App\Application\UseCases\DetalleOportunidad;

use App\Application\Enums\DeleteOutcome;
use App\Application\Services\RecomputeOportunidadTotalService;
use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use Illuminate\Database\QueryException;

class DestroyDetalleOportunidadUseCase
{
    public function __construct(
        private DetalleOportunidadRepositoryInterface $repository,
        private RecomputeOportunidadTotalService $recomputeService,
    ) {}

    /**
     * Soft-deletes a detalle. Returns a typed outcome so the controller can
     * map to precise HTTP codes (200 / 404 / 422).
     *
     * - `DeleteOutcome::Deleted`   → 200, soft-deleted successfully
     * - `DeleteOutcome::NotFound`  → 404, no such detalle
     * - `DeleteOutcome::FkBlocked` → 422, FK constraint violation (SQLSTATE 23000)
     */
    public function execute(int $id): DeleteOutcome
    {
        // Capture oportunidad_id BEFORE deletion so we can recompute parent totals
        // even after the row is soft-deleted (Eloquent default scope excludes trashed).
        $existing = $this->repository->findById($id);
        if (! $existing) {
            return DeleteOutcome::NotFound;
        }

        $oportunidadId = $existing->oportunidad_id;

        try {
            $deleted = $this->repository->delete($id);
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? null;
            if ($sqlState === '23000') {
                return DeleteOutcome::FkBlocked;
            }
            // Generic QueryException: re-throw — becomes 500.
            throw $e;
        }

        if (! $deleted) {
            return DeleteOutcome::NotFound;
        }

        // Recompute parent Oportunidad aggregate total (no-op if column absent).
        $this->recomputeService->recompute($oportunidadId);

        return DeleteOutcome::Deleted;
    }
}
