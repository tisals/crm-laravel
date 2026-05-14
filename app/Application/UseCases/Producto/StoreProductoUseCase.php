<?php

namespace App\Application\UseCases\Producto;

use App\Domain\Repositories\ProductoRepositoryInterface;

class StoreProductoUseCase
{
    public function __construct(
        private ProductoRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
