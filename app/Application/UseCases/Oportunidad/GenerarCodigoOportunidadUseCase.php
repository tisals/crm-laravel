<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;

class GenerarCodigoOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
    ) {}

    /**
     * Compute the next opportunity code for the given calendar window.
     *
     * The caller (typically `StoreOportunidadUseCase`) decides which year and
     * semester are active — usually by reading `now()` inside a `DB::transaction`
     * so that `Carbon::setTestNow()` affects both the time source and the
     * lockForUpdate() read.
     */
    public function execute(int $year, int $semester): string
    {
        return $this->repository->getNextCodigo($year, $semester);
    }
}
