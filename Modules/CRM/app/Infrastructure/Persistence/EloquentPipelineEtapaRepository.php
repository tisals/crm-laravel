<?php

namespace Modules\CRM\Infrastructure\Persistence;

use App\Domain\Entities\PipelineEtapa as PipelineEtapaEntity;
use App\Infrastructure\Persistence\BaseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Domain\Repositories\PipelineEtapaRepositoryInterface;
use Modules\CRM\Models\PipelineEtapa;

class EloquentPipelineEtapaRepository extends BaseRepository implements PipelineEtapaRepositoryInterface
{
    protected function getModelClass(): string
    {
        return PipelineEtapa::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return PipelineEtapaEntity::fromArray($model->toArray());
    }

    public function all(): array
    {
        return $this->newQuery()
            ->get()
            ->map(fn (Model $model) => $this->mapModelToEntity($model))
            ->all();
    }

    public function find(int $id): ?PipelineEtapaEntity
    {
        return $this->findById($id);
    }

    public function findByPipeline(int $pipelineId): array
    {
        return $this->newQuery()
            ->where('pipeline_id', $pipelineId)
            ->orderBy('orden')
            ->get()
            ->map(fn (Model $model) => $this->mapModelToEntity($model))
            ->all();
    }

    public function create(array $data): PipelineEtapaEntity
    {
        return parent::create($data);
    }

    public function update(int $id, array $data): PipelineEtapaEntity
    {
        $result = parent::update($id, $data);

        if (! $result) {
            throw new \RuntimeException("PipelineEtapa with ID {$id} not found for update.");
        }

        return $result;
    }

    public function delete(int $id): bool
    {
        return parent::delete($id);
    }

    public function reorder(int $pipelineId, array $orderedIds): void
    {
        $models = $this->newQuery()
            ->whereIn('id', $orderedIds)
            ->get();

        // Validate all IDs belong to the same pipeline
        foreach ($models as $model) {
            if ($model->pipeline_id !== $pipelineId) {
                throw new \InvalidArgumentException('All etapas must belong to the same pipeline');
            }
        }

        // Validate we found all provided IDs
        if ($models->count() !== count($orderedIds)) {
            throw new \InvalidArgumentException('Some etapas were not found');
        }

        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $id) {
                PipelineEtapa::where('id', $id)->update(['orden' => $index + 1]);
            }
        });
    }

    public function maxOrden(int $pipelineId): int
    {
        $model = $this->newQuery()
            ->where('pipeline_id', $pipelineId)
            ->orderBy('orden', 'desc')
            ->first();

        return $model ? $model->orden : 0;
    }
}
