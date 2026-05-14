<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Permiso as PermisoEntity;
use App\Domain\Repositories\PermisoRepositoryInterface;
use App\Models\Permiso as EloquentPermiso;
use Illuminate\Database\Eloquent\Model;

class EloquentPermisoRepository extends BaseRepository implements PermisoRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentPermiso::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return PermisoEntity::fromArray($model->toArray());
    }
}
