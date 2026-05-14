<?php

namespace App\Application\UseCases\Colaborador;

use App\Domain\Repositories\ColaboradorRepositoryInterface;

class ShowColaboradorUseCase
{
    public function __construct(
        private ColaboradorRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
