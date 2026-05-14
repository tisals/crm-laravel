<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\LugarEntidad as LugarEntidadEntity;
use App\Domain\Repositories\LugarEntidadRepositoryInterface;
use App\Models\LugarEntidad as EloquentLugarEntidad;
use Illuminate\Database\Eloquent\Model;

class EloquentLugarEntidadRepository extends BaseRepository implements LugarEntidadRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentLugarEntidad::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return LugarEntidadEntity::fromArray($model->toArray());
    }
}
