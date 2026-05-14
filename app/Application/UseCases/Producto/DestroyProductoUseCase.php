<?php

namespace App\Application\UseCases\Producto;

use App\Domain\Repositories\ProductoRepositoryInterface;

class DestroyProductoUseCase
{
    public function __construct(
        private ProductoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
