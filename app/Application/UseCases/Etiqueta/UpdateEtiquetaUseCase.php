<?php

namespace App\Application\UseCases\Etiqueta;

use App\Domain\Repositories\EtiquetaRepositoryInterface;

class UpdateEtiquetaUseCase
{
    public function __construct(
        private EtiquetaRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        return $this->repository->update($id, $data);
    }
}
