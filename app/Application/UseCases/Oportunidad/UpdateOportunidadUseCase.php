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

        // If estado is changing FROM Ganada, close the open `cliente`
        // pivot row when no other won opps exist. Commit 5.5 removed
        // `entidad.cliente_desde`; `Entidad::clearCliente()` carries
        // the same semantic ("the entity is no longer a cliente").
        //
        // IMPORTANT: use `Oportunidad::query()` (the Eloquent model on
        // the master connection) instead of `$this->repository->findById()`.
        // The repository wraps the read in `mapModelToEntity()` which
        // returns the domain entity; in test runs with `RefreshDatabase`,
        // the entity's `estado` sometimes lags the actual DB state
        // because the repository's read path goes through the read
        // replica fallback even when configured the same. The raw
        // Eloquent query hits the master connection the test writes to,
        // so we always see the post-update estado.
        if (isset($data['estado']) && $data['estado'] !== 'Ganada') {
            $estadoActual = Oportunidad::where('id', $id)->value('estado');
            if ($estadoActual === 'Ganada') {
                $oportunidadEntidadId = Oportunidad::where('id', $id)->value('entidad_id');

                $hasOtherWon = Oportunidad::where('entidad_id', $oportunidadEntidadId)
                    ->where('estado', 'Ganada')
                    ->where('id', '!=', $id)
                    ->exists();

                if (! $hasOtherWon) {
                    $entidad = Entidad::find($oportunidadEntidadId);
                    $entidad?->clearCliente();
                }
            }
        }

        return $this->repository->update($id, $data);
    }
}
