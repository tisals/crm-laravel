<?php

namespace App\Application\UseCases\OrdenServicio;

use App\Domain\Repositories\OrdenServicioRepositoryInterface;

class UpdateOrdenServicioUseCase
{
    public function __construct(
        private OrdenServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
