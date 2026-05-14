<?php

namespace App\Application\UseCases\Ciudad;

use App\Domain\Repositories\CiudadRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexCiudadUseCase
{
    public function __construct(
        private CiudadRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
