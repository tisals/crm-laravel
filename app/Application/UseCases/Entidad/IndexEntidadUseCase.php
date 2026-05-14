<?php

namespace App\Application\UseCases\Entidad;

use App\Domain\Repositories\EntidadRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexEntidadUseCase
{
    public function __construct(
        private EntidadRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
