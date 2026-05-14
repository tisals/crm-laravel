<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;

class DestroyOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
