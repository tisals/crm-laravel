<?php

namespace App\Application\UseCases\Entidad;

use App\Domain\Repositories\EntidadRepositoryInterface;

class ShowEntidadUseCase
{
    public function __construct(
        private EntidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
