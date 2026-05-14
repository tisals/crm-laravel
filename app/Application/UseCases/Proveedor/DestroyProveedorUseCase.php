<?php

namespace App\Application\UseCases\Proveedor;

use App\Domain\Repositories\ProveedorRepositoryInterface;

class DestroyProveedorUseCase
{
    public function __construct(
        private ProveedorRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
