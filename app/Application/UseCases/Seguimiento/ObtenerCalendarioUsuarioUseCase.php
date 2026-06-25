<?php

namespace App\Application\UseCases\Seguimiento;

use App\Domain\Repositories\SeguimientoRepositoryInterface;
use Illuminate\Support\Collection;

class ObtenerCalendarioUsuarioUseCase
{
    public function __construct(
        private SeguimientoRepositoryInterface $repository,
    ) {}

    /**
     * Fetch seguimientos in a date range for the calendar view.
     *
     * @return Collection<int, mixed>
     */
    public function execute(int $userId, string $fechaDesde, string $fechaHasta, ?string $estado = null): Collection
    {
        return $this->repository->findCalendarForUser($userId, $fechaDesde, $fechaHasta, $estado);
    }
}
