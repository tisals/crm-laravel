<?php

namespace App\Application\UseCases\Producto;

use App\Domain\Repositories\ProductoRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexProductoUseCase
{
    public function __construct(
        private ProductoRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
