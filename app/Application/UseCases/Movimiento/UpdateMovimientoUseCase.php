<?php

namespace App\Application\UseCases\Movimiento;

use App\Domain\Repositories\MovimientoRepositoryInterface;

class UpdateMovimientoUseCase
{
    public function __construct(
        private MovimientoRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
