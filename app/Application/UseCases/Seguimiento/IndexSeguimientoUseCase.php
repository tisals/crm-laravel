<?php

namespace App\Application\UseCases\Seguimiento;

use App\Domain\Repositories\SeguimientoRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexSeguimientoUseCase
{
    public function __construct(
        private SeguimientoRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
