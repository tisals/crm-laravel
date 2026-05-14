<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;

class ShowOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
