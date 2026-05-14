<?php

namespace App\Application\UseCases\DetalleOportunidad;

use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;

class ShowDetalleOportunidadUseCase
{
    public function __construct(
        private DetalleOportunidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
