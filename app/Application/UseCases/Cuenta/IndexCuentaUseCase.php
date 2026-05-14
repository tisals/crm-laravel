<?php

namespace App\Application\UseCases\Cuenta;

use App\Domain\Repositories\CuentaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexCuentaUseCase
{
    public function __construct(
        private CuentaRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
