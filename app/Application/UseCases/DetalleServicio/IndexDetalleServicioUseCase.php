<?php

namespace App\Application\UseCases\DetalleServicio;

use App\Domain\Repositories\DetalleServicioRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexDetalleServicioUseCase
{
    public function __construct(
        private DetalleServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
