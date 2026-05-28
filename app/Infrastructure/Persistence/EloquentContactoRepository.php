<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Contacto as ContactoEntity;
use App\Domain\Repositories\ContactoRepositoryInterface;
use App\Models\Contacto as EloquentContacto;
use Illuminate\Database\Eloquent\Model;

class EloquentContactoRepository extends BaseRepository implements ContactoRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentContacto::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return ContactoEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombres', 'like', "%{$search}%")
              ->orWhere('apellidos', 'like', "%{$search}%")
              ->orWhere('email_contacto', 'like', "%{$search}%");
        });
    }

    /**
     * Query that always brings the entidad name for display in frontend lists.
     */
    protected function newQueryWithEntidad()
    {
        return EloquentContacto::query()
            ->leftJoin('entidad', 'contacto.entidad_id', '=', 'entidad.id')
            ->select('contacto.*', 'entidad.nombre as entidad_nombre');
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = $this->newQueryWithEntidad();

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (!empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        $sortBy = $sortBy ?? 'contacto.created_at';
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'desc';

        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    public function findById(int $id): mixed
    {
        $model = $this->newQueryWithEntidad()->find($id);

        return $model ? $this->mapModelToEntity($model) : null;
    }
}
