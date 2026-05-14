<?php

namespace App\Application\UseCases\Proveedor;

use App\Domain\Repositories\ProveedorRepositoryInterface;

class StoreProveedorUseCase
{
    public function __construct(
        private ProveedorRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
