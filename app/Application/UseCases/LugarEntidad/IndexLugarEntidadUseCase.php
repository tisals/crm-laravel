<?php

namespace App\Application\UseCases\LugarEntidad;

use App\Domain\Repositories\LugarEntidadRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexLugarEntidadUseCase
{
    public function __construct(
        private LugarEntidadRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
