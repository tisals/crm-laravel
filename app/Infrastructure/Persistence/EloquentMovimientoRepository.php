<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Movimiento as MovimientoEntity;
use App\Domain\Repositories\MovimientoRepositoryInterface;
use App\Models\Movimiento as EloquentMovimiento;
use Illuminate\Database\Eloquent\Model;

class EloquentMovimientoRepository extends BaseRepository implements MovimientoRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentMovimiento::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return MovimientoEntity::fromArray($model->toArray());
    }
}
