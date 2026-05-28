<?php

namespace App\Application\UseCases\Maestro;

use App\Domain\Repositories\MaestroRepositoryInterface;

class UpdateMaestroUseCase
{
    public function __construct(
        private MaestroRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
