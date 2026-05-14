<?php

namespace App\Application\UseCases\Etiqueta;

use App\Domain\Repositories\EtiquetaRepositoryInterface;

class StoreEtiquetaUseCase
{
    public function __construct(
        private EtiquetaRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        return $this->repository->create($data);
    }
}
