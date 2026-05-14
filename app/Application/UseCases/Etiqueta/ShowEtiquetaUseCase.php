<?php

namespace App\Application\UseCases\Etiqueta;

use App\Domain\Repositories\EtiquetaRepositoryInterface;

class ShowEtiquetaUseCase
{
    public function __construct(
        private EtiquetaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): mixed
    {
        return $this->repository->findById($id);
    }
}
