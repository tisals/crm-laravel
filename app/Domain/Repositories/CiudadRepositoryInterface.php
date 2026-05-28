<?php

namespace App\Domain\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;

interface CiudadRepositoryInterface
{
    public function paginate(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator;
    public function findById(string $codMunicipio): mixed;
    public function create(array $data): mixed;
    public function update(string $codMunicipio, array $data): mixed;
    public function delete(string $codMunicipio): bool;
}
