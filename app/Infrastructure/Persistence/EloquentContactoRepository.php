<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Contacto as ContactoEntity;
use App\Domain\Repositories\ContactoRepositoryInterface;
use App\Models\Contacto as EloquentContacto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

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

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = $this->newQueryWithEntidad();

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (! empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        $sortBy = $sortBy ?? 'contacto.created_at';
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'desc';

        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    protected function applyFilters($query, array $filters): Builder
    {
        // Auto-filter by entidad_usuario for Comercial role
        $user = Auth::user();
        if ($user && $user->rol?->nombre === 'Comercial') {
            $query->whereIn('contacto.entidad_id', function ($q) use ($user) {
                $q->select('entidad_id')
                    ->from('entidad_usuario')
                    ->where('usuario_id', $user->id);
            });
        }

        return parent::applyFilters($query, $filters);
    }

    public function findById(int $id): mixed
    {
        $model = $this->newQueryWithEntidad()->find($id);

        return $model ? $this->mapModelToEntity($model) : null;
    }
}
