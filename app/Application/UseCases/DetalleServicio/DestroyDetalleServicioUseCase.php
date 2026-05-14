<?php

namespace App\Application\UseCases\DetalleServicio;

use App\Domain\Repositories\DetalleServicioRepositoryInterface;

class DestroyDetalleServicioUseCase
{
    public function __construct(
        private DetalleServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
