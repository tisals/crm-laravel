<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Models\Entidad;
use Illuminate\Support\Facades\DB;

class StoreOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
        private GenerarCodigoOportunidadUseCase $generarCodigoUseCase,
    ) {}

    public function execute(array $data): mixed
    {
        // Wrap the whole flow in a transaction so:
        //  1. `getNextCodigo()`'s `lockForUpdate()` actually serializes the
        //     SELECT MAX(...) read against concurrent inserts on InnoDB/MySQL.
        //  2. The Ganada → set `cliente_desde` side-effect rolls back if the
        //     oportunidad insert fails (no orphan cliente_desde).
        return DB::transaction(function () use ($data) {
            // Compute year/semester INSIDE the transaction so a Carbon::setTestNow()
            // override is honoured both for the codigo generator and the now() calls
            // that follow.
            $year = (int) now()->format('Y');
            $semester = (int) now()->format('n') <= 6 ? 1 : 2;

            $data['codigo'] = $this->generarCodigoUseCase->execute($year, $semester);

            $oportunidad = $this->repository->create($data);

            // If created directly as Ganada, set cliente_desde
            if (isset($data['estado']) && $data['estado'] === 'Ganada') {
                Entidad::where('id', $oportunidad->entidad_id)
                    ->whereNull('cliente_desde')
                    ->update(['cliente_desde' => now()]);
            }

            return $oportunidad;
        });
    }
}
