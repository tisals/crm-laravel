<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;

class StoreOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
        private GenerarCodigoOportunidadUseCase $generarCodigoUseCase,
    ) {}

    public function execute(array $data): mixed
    {
        $data['codigo'] = $this->generarCodigoUseCase->execute();

        return $this->repository->create($data);
    }
}
