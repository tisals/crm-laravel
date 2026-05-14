<?php

namespace App\Application\UseCases\Permiso;

use App\Domain\Repositories\PermisoRepositoryInterface;

class ShowPermisoUseCase
{
    public function __construct(
        private PermisoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
