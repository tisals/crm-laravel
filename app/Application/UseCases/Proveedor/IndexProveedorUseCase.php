<?php

namespace App\Application\UseCases\Proveedor;

use App\Domain\Repositories\ProveedorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexProveedorUseCase
{
    public function __construct(
        private ProveedorRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
