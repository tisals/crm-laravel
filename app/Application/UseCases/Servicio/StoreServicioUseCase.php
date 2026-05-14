<?php

namespace App\Application\UseCases\Servicio;

use App\Domain\Repositories\ServicioRepositoryInterface;

class StoreServicioUseCase
{
    public function __construct(
        private ServicioRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
