<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Colaborador as ColaboradorEntity;
use App\Domain\Repositories\ColaboradorRepositoryInterface;
use App\Models\Colaborador as EloquentColaborador;
use Illuminate\Database\Eloquent\Model;

class EloquentColaboradorRepository extends BaseRepository implements ColaboradorRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentColaborador::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return ColaboradorEntity::fromArray($model->toArray());
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
