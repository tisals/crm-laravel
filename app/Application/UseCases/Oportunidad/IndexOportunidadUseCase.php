<?php

namespace App\Application\UseCases\Oportunidad;

use App\Domain\Repositories\OportunidadRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexOportunidadUseCase
{
    public function __construct(
        private OportunidadRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
