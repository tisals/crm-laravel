<?php

namespace App\Application\UseCases\Seguimiento;

use App\Domain\Repositories\SeguimientoRepositoryInterface;

class ShowSeguimientoUseCase
{
    public function __construct(
        private SeguimientoRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
