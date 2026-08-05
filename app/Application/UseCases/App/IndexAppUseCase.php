<?php

namespace App\Application\UseCases\App;

use App\Domain\Repositories\AppRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexAppUseCase
{
    public function __construct(
        private AppRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
