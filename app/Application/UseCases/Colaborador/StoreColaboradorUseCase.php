<?php

namespace App\Application\UseCases\Colaborador;

use App\Domain\Repositories\ColaboradorRepositoryInterface;

class StoreColaboradorUseCase
{
    public function __construct(
        private ColaboradorRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
