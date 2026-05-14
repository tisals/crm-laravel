<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Cuenta as CuentaEntity;
use App\Domain\Repositories\CuentaRepositoryInterface;
use App\Models\Cuenta as EloquentCuenta;
use Illuminate\Database\Eloquent\Model;

class EloquentCuentaRepository extends BaseRepository implements CuentaRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentCuenta::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return CuentaEntity::fromArray($model->toArray());
    }
}
