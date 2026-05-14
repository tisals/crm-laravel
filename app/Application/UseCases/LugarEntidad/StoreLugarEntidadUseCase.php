<?php

namespace App\Application\UseCases\LugarEntidad;

use App\Domain\Repositories\LugarEntidadRepositoryInterface;

class StoreLugarEntidadUseCase
{
    public function __construct(
        private LugarEntidadRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
