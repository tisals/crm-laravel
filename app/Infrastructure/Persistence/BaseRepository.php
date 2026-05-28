<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class BaseRepository
{
    abstract protected function getModelClass(): string;

    abstract protected function mapModelToEntity(Model $model): mixed;

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = $this->newQuery();

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (!empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        $sortBy = $sortBy ?? 'created_at';
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'desc';

        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    public function findById(int $id): mixed
    {
        $model = $this->newQuery()->find($id);

        return $model ? $this->mapModelToEntity($model) : null;
    }

    public function create(array $data): mixed
    {
        $model = $this->newQuery()->create($data);

        return $this->mapModelToEntity($model);
    }

    public function update(int $id, array $data): mixed
    {
        $model = $this->newQuery()->find($id);

        if (!$model) {
            return null;
        }

        $model->update($data);

        return $this->mapModelToEntity($model->fresh());
    }

    public function delete(int $id): bool
    {
        $model = $this->newQuery()->find($id);

        if (!$model) {
            return false;
        }

        return $model->delete();
    }

    protected function newQuery()
    {
        $modelClass = $this->getModelClass();

        return $modelClass::query();
    }

    protected function applySearch($query, string $search)
    {
        return $query->where('nombre', 'like', "%{$search}%");
    }

    protected function applyFilters($query, array $filters)
    {
        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                $query->where($field, $value);
            }
        }

        return $query;
    }
}
