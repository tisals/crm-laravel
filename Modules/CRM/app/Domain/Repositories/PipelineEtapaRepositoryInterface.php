<?php

namespace Modules\CRM\Domain\Repositories;

use App\Domain\Entities\PipelineEtapa;

interface PipelineEtapaRepositoryInterface
{
    public function all(): array;

    public function find(int $id): ?PipelineEtapa;

    public function findByPipeline(int $pipelineId): array;

    public function create(array $data): PipelineEtapa;

    public function update(int $id, array $data): PipelineEtapa;

    public function delete(int $id): bool;

    public function reorder(int $pipelineId, array $orderedIds): void;

    public function maxOrden(int $pipelineId): int;
}
