<?php

namespace App\Application\UseCases\Entidad;

use App\Domain\Repositories\EntidadRepositoryInterface;

class DestroyEntidadUseCase
{
    public function __construct(
        private EntidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
