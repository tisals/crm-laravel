<?php

namespace App\Application\UseCases\Servicio;

use App\Domain\Repositories\ServicioRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexServicioUseCase
{
    public function __construct(
        private ServicioRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
