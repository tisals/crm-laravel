<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\DetalleServicio as DetalleServicioEntity;
use App\Domain\Repositories\DetalleServicioRepositoryInterface;
use App\Models\DetalleServicio as EloquentDetalleServicio;
use Illuminate\Database\Eloquent\Model;

class EloquentDetalleServicioRepository extends BaseRepository implements DetalleServicioRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentDetalleServicio::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return DetalleServicioEntity::fromArray($model->toArray());
    }
}
