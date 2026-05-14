<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\OrdenServicio as OrdenServicioEntity;
use App\Domain\Repositories\OrdenServicioRepositoryInterface;
use App\Models\OrdenServicio as EloquentOrdenServicio;
use Illuminate\Database\Eloquent\Model;

class EloquentOrdenServicioRepository extends BaseRepository implements OrdenServicioRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentOrdenServicio::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return OrdenServicioEntity::fromArray($model->toArray());
    }
}
