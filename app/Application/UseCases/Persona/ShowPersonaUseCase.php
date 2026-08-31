<?php

namespace App\Application\UseCases\Persona;

use App\Application\Auth\PersonaLookupResult;
use App\Application\Services\MultiAppRbacService;
use App\Domain\Repositories\PersonaRepositoryInterface;

/**
 * PR-I 6a.12 — tenant-aware show.
 *
 * Same lookup contract as before (findById), but now returns a
 * `PersonaLookupResult` so the controller can distinguish 404 from 403.
 *
 * Tenant check (REQ-PRAPI-003, R-10):
 *   Compare `persona.entidad_id` to the requester's primary `entidad_id`.
 *   - MATCH                → FOUND
 *   - MISMATCH + admin     → FOUND   (admin bypass via wildcard `vista='*'`)
 *   - MISMATCH + non-admin → FORBIDDEN
 *   - persona not in DB    → NOT_FOUND
 *
 * The "primary entidad id" for a non-admin comes from the user's first
 * `entidad_usuario` binding; if the user has no binding at all, the
 * tenant check is bypassed (Sanctum auth is the only gate).
 *
 * The admin check uses `MultiAppRbacService` with the wildcard `*` —
 * same trick as RbacMiddleware's fast path. If the user has ANY rol-granted
 * `vista='*'`, they're a super-admin and skip isolation.
 */
class ShowPersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
        private MultiAppRbacService $rbac,
    ) {}

    public function execute(int $id, int $userId, ?int $userEntidadId): PersonaLookupResult
    {
        $persona = $this->repository->findById($id);
        if ($persona === null) {
            return PersonaLookupResult::notFound();
        }

        // Admin bypass: rol has `vista='*'` AND is active. Same check the
        // rbac middleware uses, but applied here for the per-persona
        // tenant decision (the middleware already ran on the route).
        if ($this->rbac->hasPermission($userId, null, '*')) {
            return PersonaLookupResult::found($persona);
        }

        // Non-admin path: enforce the entidad match. A user with no
        // `entidad_usuario` binding has no enforcement scope yet.
        if ($userEntidadId !== null && (int) $persona->entidad_id !== $userEntidadId) {
            return PersonaLookupResult::forbidden();
        }

        return PersonaLookupResult::found($persona);
    }
}
