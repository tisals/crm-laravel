<?php

namespace App\Application\UseCases\Movimiento;

use App\Domain\Repositories\MovimientoRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexMovimientoUseCase
{
    public function __construct(
        private MovimientoRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
