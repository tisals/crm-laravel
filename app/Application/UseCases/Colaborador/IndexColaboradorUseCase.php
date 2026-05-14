<?php

namespace App\Application\UseCases\Colaborador;

use App\Domain\Repositories\ColaboradorRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexColaboradorUseCase
{
    public function __construct(
        private ColaboradorRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
