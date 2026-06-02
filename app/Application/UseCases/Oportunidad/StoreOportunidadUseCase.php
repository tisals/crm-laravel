<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;

class StoreOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
        private GenerarCodigoOportunidadUseCase $generarCodigoUseCase,
    ) {}

    public function execute(array $data): mixed
    {
        $data['codigo'] = $this->generarCodigoUseCase->execute();

        $oportunidad = $this->repository->create($data);

        // If created directly as Ganada, set cliente_desde
        if (isset($data['estado']) && $data['estado'] === 'Ganada') {
            \App\Models\Entidad::where('id', $oportunidad->entidad_id)
                ->whereNull('cliente_desde')
                ->update(['cliente_desde' => now()]);
        }

        return $oportunidad;
    }
}
