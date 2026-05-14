<?php

namespace App\Application\UseCases\DetalleServicio;

use App\Domain\Repositories\DetalleServicioRepositoryInterface;

class ShowDetalleServicioUseCase
{
    public function __construct(
        private DetalleServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
