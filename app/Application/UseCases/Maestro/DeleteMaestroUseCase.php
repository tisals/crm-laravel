<?php

namespace App\Application\UseCases\Maestro;

use App\Domain\Repositories\MaestroRepositoryInterface;

class DeleteMaestroUseCase
{
    public function __construct(
        private MaestroRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
