<?php

namespace App\Application\UseCases\Rol;

use App\Domain\Repositories\RolRepositoryInterface;

class DestroyRolUseCase
{
    public function __construct(
        private RolRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
