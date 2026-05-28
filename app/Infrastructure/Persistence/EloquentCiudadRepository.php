<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Ciudad as CiudadEntity;
use App\Domain\Repositories\CiudadRepositoryInterface;
use App\Models\Ciudad as EloquentCiudad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentCiudadRepository extends BaseRepository implements CiudadRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentCiudad::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return CiudadEntity::fromArray($model->toArray());
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = EloquentCiudad::query();

        if ($search) {
            $query->where('nombre', 'like', "%{$search}%");
        }

        if (!empty($filters['departamento'])) {
            $query->where('departamento', $filters['departamento']);
        }

        return $query->orderBy('nombre')->paginate($perPage);
    }

    public function findById(int|string $id): mixed
    {
        $model = EloquentCiudad::find($id);

        return $model ? $this->mapModelToEntity($model) : null;
    }

    public function create(array $data): mixed
    {
        $model = EloquentCiudad::create($data);

        return $this->mapModelToEntity($model);
    }

    public function update(int|string $id, array $data): mixed
    {
        $model = EloquentCiudad::find($id);

        if (!$model) {
            return null;
        }

        $model->update($data);

        return $this->mapModelToEntity($model->fresh());
    }

    public function delete(int|string $id): bool
    {
        $model = EloquentCiudad::find($id);

        if (!$model) {
            return false;
        }

        return $model->delete();
    }
}
