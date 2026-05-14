<?php

namespace App\Application\UseCases\Permiso;

use App\Domain\Repositories\PermisoRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexPermisoUseCase
{
    public function __construct(
        private PermisoRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
