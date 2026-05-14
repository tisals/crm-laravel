<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Seguimiento as SeguimientoEntity;
use App\Domain\Repositories\SeguimientoRepositoryInterface;
use App\Models\Seguimiento as EloquentSeguimiento;
use Illuminate\Database\Eloquent\Model;

class EloquentSeguimientoRepository extends BaseRepository implements SeguimientoRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentSeguimiento::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return SeguimientoEntity::fromArray($model->toArray());
    }
}
