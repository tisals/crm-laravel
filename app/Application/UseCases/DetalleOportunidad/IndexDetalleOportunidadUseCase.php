<?php

namespace App\Application\UseCases\DetalleOportunidad;

use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexDetalleOportunidadUseCase
{
    public function __construct(
        private DetalleOportunidadRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
