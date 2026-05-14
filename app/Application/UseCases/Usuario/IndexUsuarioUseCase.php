<?php

namespace App\Application\UseCases\Usuario;

use App\Domain\Repositories\UsuarioRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexUsuarioUseCase
{
    public function __construct(
        private UsuarioRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
