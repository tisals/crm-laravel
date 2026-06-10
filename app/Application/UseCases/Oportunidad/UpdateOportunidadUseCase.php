<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Models\Entidad;
use App\Models\Oportunidad;

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

        // If estado is changing FROM Ganada, clear cliente_desde and set estado back to Activo if no other won opps exist
        if (isset($data['estado']) && $data['estado'] !== 'Ganada') {
            $oportunidad = $this->repository->findById($id);
            if ($oportunidad && $oportunidad->estado === 'Ganada') {
                $hasOtherWon = Oportunidad::where('entidad_id', $oportunidad->entidad_id)
                    ->where('estado', 'Ganada')
                    ->where('id', '!=', $oportunidad->id)
                    ->exists();

                if (! $hasOtherWon) {
                    Entidad::where('id', $oportunidad->entidad_id)
                        ->update([
                            'cliente_desde' => null,
                            'estado' => 'Activo',
                        ]);
                }
            }
        }

        return $this->repository->update($id, $data);
    }
}
