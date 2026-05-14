<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Producto as ProductoEntity;
use App\Domain\Repositories\ProductoRepositoryInterface;
use App\Models\Producto as EloquentProducto;
use Illuminate\Database\Eloquent\Model;

class EloquentProductoRepository extends BaseRepository implements ProductoRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentProducto::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return ProductoEntity::fromArray($model->toArray());
    }
}
