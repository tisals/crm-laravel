<?php

namespace App\Domain\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;

interface AppRepositoryInterface
{
    public function paginate(int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator;

    public function findById(int $id): mixed;

    public function create(array $data): mixed;

    public function update(int $id, array $data): mixed;

    public function delete(int $id): bool;

    public function findBySlug(string $slug): mixed;

    public function allActive(): array;
}
