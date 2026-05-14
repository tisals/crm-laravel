<?php

namespace App\Application\UseCases\LugarEntidad;

use App\Domain\Repositories\LugarEntidadRepositoryInterface;

class ShowLugarEntidadUseCase
{
    public function __construct(
        private LugarEntidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
