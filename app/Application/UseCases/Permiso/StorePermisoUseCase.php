<?php

namespace App\Application\UseCases\Permiso;

use App\Domain\Repositories\PermisoRepositoryInterface;

class StorePermisoUseCase
{
    public function __construct(
        private PermisoRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
