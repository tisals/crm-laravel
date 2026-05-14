<?php

namespace App\Application\UseCases\Proveedor;

use App\Domain\Repositories\ProveedorRepositoryInterface;

class UpdateProveedorUseCase
{
    public function __construct(
        private ProveedorRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
