<?php

namespace App\Application\UseCases\Servicio;

use App\Domain\Repositories\ServicioRepositoryInterface;

class ShowServicioUseCase
{
    public function __construct(
        private ServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
