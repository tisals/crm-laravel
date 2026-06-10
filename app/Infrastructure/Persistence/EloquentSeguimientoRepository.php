<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Seguimiento as SeguimientoEntity;
use App\Domain\Repositories\SeguimientoRepositoryInterface;
use App\Models\Seguimiento as EloquentSeguimiento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EloquentSeguimientoRepository extends BaseRepository implements SeguimientoRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentSeguimiento::class;
    }

    protected function newQuery()
    {
        $modelClass = $this->getModelClass();

        return $modelClass::with(['autor', 'contacto', 'entidad', 'oportunidad']);
    }

    protected function applyFilters($query, array $filters): Builder
    {
        // Auto-filter by entidad_usuario for Comercial role
        $user = Auth::user();
        if ($user && $user->rol?->nombre === 'Comercial') {
            $query->whereIn('entidad_id', function ($q) use ($user) {
                $q->select('entidad_id')
                    ->from('entidad_usuario')
                    ->where('usuario_id', $user->id);
            });
        }

        return parent::applyFilters($query, $filters);
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return SeguimientoEntity::fromArray($model->toArray());
    }
}
