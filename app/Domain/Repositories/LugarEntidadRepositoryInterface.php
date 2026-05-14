<?php

namespace App\Domain\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;

interface LugarEntidadRepositoryInterface
{
    public function paginate(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator;
    public function findById(int $id): mixed;
    public function create(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function delete(int $id): bool;
}
