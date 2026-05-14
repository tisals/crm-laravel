<?php

namespace App\Application\UseCases\Ciudad;

use App\Domain\Repositories\CiudadRepositoryInterface;

class ShowCiudadUseCase
{
    public function __construct(
        private CiudadRepositoryInterface $repository,
    ) {}

    public function execute(string $codMunicipio): mixed
    {
        return $this->repository->findById($codMunicipio);
    }
}
