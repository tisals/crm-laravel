<?php

namespace App\Application\UseCases\Servicio;

use App\Domain\Repositories\ServicioRepositoryInterface;

class UpdateServicioUseCase
{
    public function __construct(
        private ServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
