<?php

namespace App\Application\UseCases\Permiso;

use App\Domain\Repositories\PermisoRepositoryInterface;

class DestroyPermisoUseCase
{
    public function __construct(
        private PermisoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
