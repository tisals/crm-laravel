<?php

namespace App\Application\UseCases\Cuenta;

use App\Domain\Repositories\CuentaRepositoryInterface;

class StoreCuentaUseCase
{
    public function __construct(
        private CuentaRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
