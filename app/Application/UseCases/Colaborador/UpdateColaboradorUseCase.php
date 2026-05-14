<?php

namespace App\Application\UseCases\Colaborador;

use App\Domain\Repositories\ColaboradorRepositoryInterface;

class UpdateColaboradorUseCase
{
    public function __construct(
        private ColaboradorRepositoryInterface $repository,
    ) {}

    public function execute(int $id, array $data): mixed
    {
        // Auto-set fecha_retiro when status changes to Inactivo
        if (isset($data['estado']) && $data['estado'] === 'Inactivo' && !isset($data['fecha_retiro'])) {
            $data['fecha_retiro'] = now()->toDateString();
        }

        return $this->repository->update($id, $data);
    }
}
