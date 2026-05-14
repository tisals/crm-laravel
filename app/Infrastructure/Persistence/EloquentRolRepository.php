<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Rol as RolEntity;
use App\Domain\Repositories\RolRepositoryInterface;
use App\Models\Rol as EloquentRol;
use Illuminate\Database\Eloquent\Model;

class EloquentRolRepository extends BaseRepository implements RolRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentRol::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return RolEntity::fromArray($model->toArray());
    }
}
