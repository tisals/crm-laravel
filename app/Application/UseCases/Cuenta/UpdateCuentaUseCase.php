<?php

namespace App\Application\UseCases\Cuenta;

use App\Domain\Repositories\CuentaRepositoryInterface;

class UpdateCuentaUseCase
{
    public function __construct(
        private CuentaRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
