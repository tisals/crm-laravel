<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Entidad as EntidadEntity;
use App\Domain\Repositories\EntidadRepositoryInterface;
use App\Models\Entidad as EloquentEntidad;
use Illuminate\Database\Eloquent\Model;

class EloquentEntidadRepository extends BaseRepository implements EntidadRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentEntidad::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return EntidadEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombre', 'like', "%{$search}%")
              ->orWhere('identificacion', 'like', "%{$search}%");
        });
    }
}
