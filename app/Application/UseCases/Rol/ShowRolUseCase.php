<?php

namespace App\Application\UseCases\Rol;

use App\Domain\Repositories\RolRepositoryInterface;

class ShowRolUseCase
{
    public function __construct(
        private RolRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
