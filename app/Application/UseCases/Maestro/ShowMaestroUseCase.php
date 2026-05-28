<?php

namespace App\Application\UseCases\Maestro;

use App\Domain\Repositories\MaestroRepositoryInterface;

class ShowMaestroUseCase
{
    public function __construct(
        private MaestroRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
