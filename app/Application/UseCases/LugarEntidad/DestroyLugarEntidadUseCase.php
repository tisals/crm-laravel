<?php

namespace App\Application\UseCases\LugarEntidad;

use App\Domain\Repositories\LugarEntidadRepositoryInterface;

class DestroyLugarEntidadUseCase
{
    public function __construct(
        private LugarEntidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
