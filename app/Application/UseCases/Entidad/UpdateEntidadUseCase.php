<?php

namespace App\Application\UseCases\Entidad;

use App\Domain\Repositories\EntidadRepositoryInterface;

class UpdateEntidadUseCase
{
    public function __construct(
        private EntidadRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
