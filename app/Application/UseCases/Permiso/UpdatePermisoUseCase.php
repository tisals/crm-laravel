<?php

namespace App\Application\UseCases\Permiso;

use App\Domain\Repositories\PermisoRepositoryInterface;

class UpdatePermisoUseCase
{
    public function __construct(
        private PermisoRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
