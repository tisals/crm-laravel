<?php

namespace App\Application\UseCases\Seguimiento;

use App\Domain\Repositories\SeguimientoRepositoryInterface;

class UpdateSeguimientoUseCase
{
    public function __construct(
        private SeguimientoRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
