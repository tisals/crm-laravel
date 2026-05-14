<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\DetalleOportunidad as DetalleOportunidadEntity;
use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Models\DetalleOportunidad as EloquentDetalleOportunidad;
use Illuminate\Database\Eloquent\Model;

class EloquentDetalleOportunidadRepository extends BaseRepository implements DetalleOportunidadRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentDetalleOportunidad::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return DetalleOportunidadEntity::fromArray($model->toArray());
    }
}
