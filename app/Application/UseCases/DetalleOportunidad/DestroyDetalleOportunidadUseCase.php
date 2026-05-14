<?php

namespace App\Application\UseCases\DetalleOportunidad;

use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;

class DestroyDetalleOportunidadUseCase
{
    public function __construct(
        private DetalleOportunidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
