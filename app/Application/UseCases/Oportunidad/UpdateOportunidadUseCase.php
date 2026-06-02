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

        // If estado is changing FROM Ganada, clear cliente_desde
        if (isset($data['estado']) && $data['estado'] !== 'Ganada') {
            $oportunidad = $this->repository->findById($id);
            if ($oportunidad && $oportunidad->estado === 'Ganada') {
                \App\Models\Entidad::where('id', $oportunidad->entidad_id)
                    ->update(['cliente_desde' => null]);
            }
        }

        return $this->repository->update($id, $data);
    }
}
