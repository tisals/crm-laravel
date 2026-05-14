<?php

namespace App\Application\UseCases\Entidad;

use App\Domain\Repositories\EntidadRepositoryInterface;

class StoreEntidadUseCase
{
    public function __construct(
        private EntidadRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
