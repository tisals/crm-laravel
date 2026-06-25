<?php

namespace App\Domain\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface SeguimientoRepositoryInterface
{
    public function paginate(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator;

    public function findById(int $id): mixed;

    public function create(array $data): mixed;

    public function update(int $id, array $data): mixed;

    public function delete(int $id): bool;

    /**
     * Paginate seguimientos visible to the given user.
     *
     * For Comercial users, only seguimientos linked to entidades in the user's
     * `entidad_usuario` rows are returned. For Admin/SuperAdmin, all seguimientos.
     *
     * @param  array<string, mixed>  $filters  Standard filter keys: estado, fecha_desde,
     *                                         fecha_hasta, tipo, oportunidad_id, contacto_id
     */
    public function findForUser(int $userId, int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator;

    /**
     * Fetch seguimientos for a calendar view (date range) for the given user.
     *
     * Returns seguimientos with fecha between $fechaDesde and $fechaHasta, scoped
     * by the user's role (same rules as findForUser).
     *
     * @return Collection<int, mixed>
     */
    public function findCalendarForUser(int $userId, string $fechaDesde, string $fechaHasta, ?string $estado = null): Collection;
}
