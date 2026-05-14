<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;

class GenerarCodigoOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
    ) {}

    public function execute(): string
    {
        return $this->repository->getNextCodigo();
    }
}
