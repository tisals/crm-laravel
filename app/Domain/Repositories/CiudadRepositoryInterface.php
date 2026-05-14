<?php

namespace App\Domain\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;

interface CiudadRepositoryInterface
{
    public function paginate(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator;
    public function findById(string $codMunicipio): mixed;
}
