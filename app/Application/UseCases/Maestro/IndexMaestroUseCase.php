<?php

namespace App\Application\UseCases\Maestro;

use App\Domain\Repositories\MaestroRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexMaestroUseCase
{
    public function __construct(
        private MaestroRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 50, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
