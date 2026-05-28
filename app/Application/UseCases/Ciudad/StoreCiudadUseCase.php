<?php

namespace App\Application\UseCases\Ciudad;

use App\Domain\Repositories\CiudadRepositoryInterface;

class StoreCiudadUseCase
{
    public function __construct(
        private CiudadRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
