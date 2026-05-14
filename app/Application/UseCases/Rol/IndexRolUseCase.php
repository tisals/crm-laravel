<?php

namespace App\Application\UseCases\Rol;

use App\Domain\Repositories\RolRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexRolUseCase
{
    public function __construct(
        private RolRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
