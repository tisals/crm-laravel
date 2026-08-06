<?php

namespace App\Domain\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;

interface UsuarioAppPermisoRepositoryInterface
{
    /**
     * List scoped permissions for a user across all apps (paginated).
     * Used by admin permission management endpoints.
     */
    public function paginate(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator;

    public function findById(int $id): mixed;

    /**
     * Find all scoped permissions for (user, app). Used to drive the
     * `/usuarios/{userId}/apps/{appId}/permisos` GET endpoint.
     *
     * @return array<UsuarioAppPermiso>
     */
    public function findByUserAndApp(int $usuarioId, int $appId): array;

    public function create(array $data): mixed;

    public function update(int $id, array $data): mixed;

    public function delete(int $id): bool;

    /**
     * Replace-all for a (user, app) scope. Atomically deletes any existing
     * rows and inserts the given vistas. Returns the new set.
     *
     * @param  array<string>  $vistas
     * @return array<UsuarioAppPermiso>
     */
    public function sync(int $usuarioId, int $appId, array $vistas): array;

    /**
     * Delete all scoped permissions for (user, app). Used when removing
     * an app from an entity so the affected users lose their per-app
     * grants. No-op if the user already has no rows.
     *
     * @return int number of rows deleted
     */
    public function deleteByUserAndApp(int $usuarioId, int $appId): int;

    /**
     * Bulk-create default scoped permissions for a (user, app) set when
     * an app is newly assigned to an entity. Wraps each row in
     * `insertOrIgnore` semantics so duplicate (user, app, vista) tuples
     * are silently skipped — idempotency contract for the cascade
     * `AssignAppToEntidadUseCase`.
     *
     * @param  array<int>    $usuarioIds
     * @param  int           $appId
     * @param  array<string> $vistas
     * @return int rows actually inserted
     */
    public function bulkCreateDefaults(array $usuarioIds, int $appId, array $vistas): int;
}
