<?php

namespace App\Application\UseCases\Persona;

use App\Domain\Repositories\PersonaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexPersonaUseCase
{
    public function __construct(
        private PersonaRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
