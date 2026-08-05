<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Persona as PersonaEntity;
use App\Domain\Repositories\PersonaRepositoryInterface;
use App\Models\Persona as EloquentPersona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentPersonaRepository extends BaseRepository implements PersonaRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentPersona::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return PersonaEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombres', 'like', "%{$search}%")
                ->orWhere('apellidos', 'like', "%{$search}%")
                ->orWhere('email_principal', 'like', "%{$search}%")
                ->orWhere('identificacion_numero', 'like', "%{$search}%")
                ->orWhere('telefono_principal', 'like', "%{$search}%");
        });
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = $this->newQuery();

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (! empty($filters)) {
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
}
