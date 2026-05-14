<?php

namespace App\Application\UseCases\OrdenServicio;

use App\Domain\Repositories\OrdenServicioRepositoryInterface;

class ShowOrdenServicioUseCase
{
    public function __construct(
        private OrdenServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
