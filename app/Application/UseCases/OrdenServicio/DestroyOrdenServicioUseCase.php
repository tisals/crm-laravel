<?php

namespace App\Application\UseCases\OrdenServicio;

use App\Domain\Repositories\OrdenServicioRepositoryInterface;

class DestroyOrdenServicioUseCase
{
    public function __construct(
        private OrdenServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
