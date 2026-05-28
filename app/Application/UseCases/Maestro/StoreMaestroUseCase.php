<?php

namespace App\Application\UseCases\Maestro;

use App\Domain\Repositories\MaestroRepositoryInterface;

class StoreMaestroUseCase
{
    public function __construct(
        private MaestroRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
