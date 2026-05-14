<?php

namespace App\Application\UseCases\Seguimiento;

use App\Domain\Repositories\SeguimientoRepositoryInterface;

class DestroySeguimientoUseCase
{
    public function __construct(
        private SeguimientoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
