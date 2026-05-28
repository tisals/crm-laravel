<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Maestro as MaestroEntity;
use App\Domain\Repositories\MaestroRepositoryInterface;
use App\Models\Maestro as EloquentMaestro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentMaestroRepository extends BaseRepository implements MaestroRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentMaestro::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return MaestroEntity::fromArray($model->toArray());
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = EloquentMaestro::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('campo', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['campo'])) {
            $query->where('campo', $filters['campo']);
        }

        return $query->orderBy('campo')->orderBy('id')->paginate($perPage);
    }

    public function findById(int $id): mixed
    {
        $model = EloquentMaestro::find($id);

        return $model ? $this->mapModelToEntity($model) : null;
    }

    public function create(array $data): mixed
    {
        $model = EloquentMaestro::create($data);

        return $this->mapModelToEntity($model);
    }

    public function update(int $id, array $data): mixed
    {
        $model = EloquentMaestro::find($id);

        if (!$model) {
            return null;
        }

        $model->update($data);

        return $this->mapModelToEntity($model->fresh());
    }

    public function delete(int $id): bool
    {
        $model = EloquentMaestro::find($id);

        if (!$model) {
            return false;
        }

        return $model->delete();
    }
}
