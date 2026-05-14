<?php

namespace App\Application\UseCases\Proveedor;

use App\Domain\Repositories\ProveedorRepositoryInterface;

class ShowProveedorUseCase
{
    public function __construct(
        private ProveedorRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
