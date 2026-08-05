<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\App as AppEntity;
use App\Domain\Repositories\AppRepositoryInterface;
use App\Models\App as EloquentApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentAppRepository extends BaseRepository implements AppRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentApp::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return AppEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('slug', 'like', "%{$search}%")
                ->orWhere('nombre', 'like', "%{$search}%");
        });
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'asc'): LengthAwarePaginator
    {
        $query = $this->newQuery();

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (! empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        $sortBy = $sortBy ?? 'nombre';
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'asc';

        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    public function findById(int $id): mixed
    {
        $model = $this->newQuery()->find($id);

        return $model ? $this->mapModelToEntity($model) : null;
    }

    public function findBySlug(string $slug): mixed
    {
        $model = $this->newQuery()->where('slug', $slug)->first();

        return $model ? $this->mapModelToEntity($model) : null;
    }

    public function allActive(): array
    {
        return $this->newQuery()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($m) => $this->mapModelToEntity($m)->toArray())
            ->toArray();
    }
}
