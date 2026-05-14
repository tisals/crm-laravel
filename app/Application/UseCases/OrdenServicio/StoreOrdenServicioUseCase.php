<?php

namespace App\Application\UseCases\OrdenServicio;

use App\Domain\Repositories\OrdenServicioRepositoryInterface;

class StoreOrdenServicioUseCase
{
    public function __construct(
        private OrdenServicioRepositoryInterface $repository,
    ) {}

    public function execute(array $data): mixed
    {
        if (empty($data['colaborador_id']) && empty($data['proveedor_id'])) {
            throw new \InvalidArgumentException('Debe especificar al menos un colaborador o proveedor.');
        }

        return $this->repository->create($data);
    }
}
