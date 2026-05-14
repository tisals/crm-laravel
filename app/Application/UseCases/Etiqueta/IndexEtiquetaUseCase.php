<?php

namespace App\Application\UseCases\Etiqueta;

use App\Domain\Repositories\EtiquetaRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class IndexEtiquetaUseCase
{
    public function __construct(
        private EtiquetaRepositoryInterface $repository,
    ) {}

    public function execute(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $search, $filters);
    }
}
