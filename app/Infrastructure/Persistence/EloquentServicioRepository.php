<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Servicio as ServicioEntity;
use App\Domain\Repositories\ServicioRepositoryInterface;
use App\Models\Servicio as EloquentServicio;
use Illuminate\Database\Eloquent\Model;

class EloquentServicioRepository extends BaseRepository implements ServicioRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentServicio::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return ServicioEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombre', 'like', "%{$search}%");
        });
    }
}
