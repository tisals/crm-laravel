<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Contacto as ContactoEntity;
use App\Domain\Repositories\ContactoRepositoryInterface;
use App\Models\Contacto as EloquentContacto;
use Illuminate\Database\Eloquent\Model;

class EloquentContactoRepository extends BaseRepository implements ContactoRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentContacto::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return ContactoEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombres', 'like', "%{$search}%")
              ->orWhere('apellidos', 'like', "%{$search}%")
              ->orWhere('email_contacto', 'like', "%{$search}%");
        });
    }
}
