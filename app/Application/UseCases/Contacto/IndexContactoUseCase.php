<?php

namespace App\Application\UseCases\Contacto;

use App\Domain\Repositories\ContactoRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexContactoUseCase
{
    public function __construct(
        private ContactoRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
