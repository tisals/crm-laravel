<?php

namespace App\Application\UseCases\Movimiento;

use App\Domain\Repositories\MovimientoRepositoryInterface;

class DestroyMovimientoUseCase
{
    public function __construct(
        private MovimientoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
