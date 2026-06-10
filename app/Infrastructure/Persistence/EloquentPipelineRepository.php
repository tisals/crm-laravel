<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Pipeline as PipelineEntity;
use App\Domain\Entities\PipelineEtapa as PipelineEtapaEntity;
use App\Domain\Repositories\PipelineRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Modules\CRM\Models\Pipeline;

class EloquentPipelineRepository extends BaseRepository implements PipelineRepositoryInterface
{
    protected function getModelClass(): string
    {
        return Pipeline::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        $data = $model->toArray();

        // Convert eager-loaded etapas (arrays) to domain entities
        if (isset($data['etapas']) && is_array($data['etapas'])) {
            $data['etapas'] = array_map(
                fn (array $etapa) => PipelineEtapaEntity::fromArray($etapa),
                $data['etapas']
            );
        }

        return PipelineEntity::fromArray($data);
    }

    public function all(): array
    {
        return $this->newQuery()
            ->with('etapas')
            ->get()
            ->map(fn (Model $model) => $this->mapModelToEntity($model))
            ->all();
    }

    public function find(int $id): ?PipelineEntity
    {
        return $this->findById($id);
    }

    public function findByCodigo(string $codigo): ?PipelineEntity
    {
        $model = $this->newQuery()->where('codigo', $codigo)->first();

        return $model ? $this->mapModelToEntity($model) : null;
    }

    public function create(array $data): PipelineEntity
    {
        return parent::create($data);
    }

    public function update(int $id, array $data): PipelineEntity
    {
        $result = parent::update($id, $data);

        if (! $result) {
            throw new \RuntimeException("Pipeline with ID {$id} not found for update.");
        }

        return $result;
    }

    public function delete(int $id): bool
    {
        return parent::delete($id);
    }
}
