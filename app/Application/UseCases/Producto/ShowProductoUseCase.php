<?php

namespace App\Application\UseCases\Producto;

use App\Domain\Repositories\ProductoRepositoryInterface;

class ShowProductoUseCase
{
    public function __construct(
        private ProductoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
