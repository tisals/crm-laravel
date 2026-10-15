<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Admin\ListUsersForSnapshotUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/admin/users/snapshot
 *
 * Flat user list for the mercurio SnapshotRefresher
 * (janus-apps-canonicalization D-D3 follow-up, 2026-10-15).
 *
 * Auth: Bearer Sanctum token. Requires rol_id=1 (SuperAdmin) or the
 * dedicated `mercurio-sync` rol once crm-laravel adds it.
 *
 * Response: `{success: true, data: [{id, email, name, estado, rol_id,
 * apps, entities, permissions}, ...]}`
 *
 * No pagination — mercurio expects one big list and runs every 60s.
 */
class AdminUserSnapshotController extends Controller
{
    use Concerns\ApiResponse;

    public function __construct(
        private ListUsersForSnapshotUseCase $useCase,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        // For now only SuperAdmin (rol_id=1) is allowed. When
        // crm-laravel adds the `mercurio-sync` rol, accept either.
        $allowedRolIds = [1];
        if (! in_array((int) $user->rol_id, $allowedRolIds, true)) {
            return $this->errorResponse('Forbidden: requires SuperAdmin.', 403);
        }

        $users = $this->useCase->execute();

        return $this->successResponse($users);
    }
}