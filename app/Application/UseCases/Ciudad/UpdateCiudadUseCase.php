<?php

namespace App\Application\UseCases\Ciudad;

use App\Domain\Repositories\CiudadRepositoryInterface;

class UpdateCiudadUseCase
{
    public function __construct(
        private CiudadRepositoryInterface $repository,
    ) {}

    public function execute(string $codMunicipio, array $data): mixed
    {
        return $this->repository->update($codMunicipio, $data);
    }
}
