<?php

namespace App\Domain\Repositories;

use App\Domain\Entities\Pipeline;

interface PipelineRepositoryInterface
{
    public function all(): array;

    public function find(int $id): ?Pipeline;

    public function findByCodigo(string $codigo): ?Pipeline;

    public function create(array $data): Pipeline;

    public function update(int $id, array $data): Pipeline;

    public function delete(int $id): bool;
}
