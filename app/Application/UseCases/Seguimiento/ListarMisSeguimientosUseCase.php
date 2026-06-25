<?php

namespace App\Application\UseCases\Seguimiento;

use App\Domain\Repositories\SeguimientoRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class ListarMisSeguimientosUseCase
{
    public function __construct(
        private SeguimientoRepositoryInterface $repository,
    ) {}

    /**
     * List seguimientos visible to the current user.
     *
     * @param  array<string, mixed>  $filters
     */
    public function execute(int $userId, int $perPage, ?string $search, array $filters): LengthAwarePaginator
    {
        return $this->repository->findForUser($userId, $perPage, $search, $filters);
    }
}
