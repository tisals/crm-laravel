<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;

class UpdateOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
        private GanarOportunidadUseCase $ganarUseCase,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        // If estado is changing to Ganada, delegate to GanarOportunidadUseCase
        if (isset($data['estado']) && $data['estado'] === 'Ganada') {
            return $this->ganarUseCase->execute($id, $data);
        }

        return $this->repository->update($id, $data);
    }
}
