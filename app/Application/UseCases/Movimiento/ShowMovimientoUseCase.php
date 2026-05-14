<?php

namespace App\Application\UseCases\Movimiento;

use App\Domain\Repositories\MovimientoRepositoryInterface;

class ShowMovimientoUseCase
{
    public function __construct(
        private MovimientoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
