<?php

namespace App\Application\UseCases\OrdenServicio;

use App\Domain\Repositories\OrdenServicioRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexOrdenServicioUseCase
{
    public function __construct(
        private OrdenServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
