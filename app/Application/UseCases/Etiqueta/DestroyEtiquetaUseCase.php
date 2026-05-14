<?php

namespace App\Application\UseCases\Etiqueta;

use App\Domain\Repositories\EtiquetaRepositoryInterface;

class DestroyEtiquetaUseCase
{
    public function __construct(
        private EtiquetaRepositoryInterface $repository,
    ) {}

    public function execute(int $id): bool
    {
        return $this->repository->delete($id);
    }
}
