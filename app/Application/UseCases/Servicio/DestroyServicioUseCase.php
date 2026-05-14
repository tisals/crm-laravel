<?php

namespace App\Application\UseCases\Servicio;

use App\Domain\Repositories\ServicioRepositoryInterface;

class DestroyServicioUseCase
{
    public function __construct(
        private ServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
