<?php

namespace App\Application\UseCases\Cuenta;

use App\Domain\Repositories\CuentaRepositoryInterface;

class DestroyCuentaUseCase
{
    public function __construct(
        private CuentaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
