<?php

namespace App\Application\UseCases\Ciudad;

use App\Domain\Repositories\CiudadRepositoryInterface;

class DeleteCiudadUseCase
{
    public function __construct(
        private CiudadRepositoryInterface $repository,
    ) {}

    public function execute(string $codMunicipio): bool
    {
        return $this->repository->delete($codMunicipio);
    }
}
