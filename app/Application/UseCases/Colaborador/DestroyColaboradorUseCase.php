<?php

namespace App\Application\UseCases\Colaborador;

use App\Domain\Repositories\ColaboradorRepositoryInterface;

class DestroyColaboradorUseCase
{
    public function __construct(
        private ColaboradorRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
