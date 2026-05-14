<?php

namespace App\Application\UseCases\Producto;

use App\Domain\Repositories\ProductoRepositoryInterface;

class UpdateProductoUseCase
{
    public function __construct(
        private ProductoRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
