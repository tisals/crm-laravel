<?php

namespace App\Application\UseCases\Cuenta;

use App\Domain\Repositories\CuentaRepositoryInterface;

class ShowCuentaUseCase
{
    public function __construct(
        private CuentaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
