<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Proveedor as ProveedorEntity;
use App\Domain\Repositories\ProveedorRepositoryInterface;
use App\Models\Proveedor as EloquentProveedor;
use Illuminate\Database\Eloquent\Model;

class EloquentProveedorRepository extends BaseRepository implements ProveedorRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentProveedor::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return ProveedorEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombres', 'like', "%{$search}%")
              ->orWhere('apellidos', 'like', "%{$search}%")
              ->orWhere('identificacion', 'like', "%{$search}%");
        });
    }
}
