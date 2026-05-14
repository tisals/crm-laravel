<?php

namespace App\Application\UseCases\Rol;

use App\Domain\Repositories\RolRepositoryInterface;

class StoreRolUseCase
{
    public function __construct(
        private RolRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
