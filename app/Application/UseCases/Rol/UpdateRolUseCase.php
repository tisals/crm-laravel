<?php

namespace App\Application\UseCases\Rol;

use App\Domain\Repositories\RolRepositoryInterface;

class UpdateRolUseCase
{
    public function __construct(
        private RolRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
