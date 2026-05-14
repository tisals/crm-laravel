<?php

namespace App\Application\UseCases\LugarEntidad;

use App\Domain\Repositories\LugarEntidadRepositoryInterface;

class UpdateLugarEntidadUseCase
{
    public function __construct(
        private LugarEntidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
