<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Entidad as EntidadEntity;
use App\Domain\Repositories\EntidadRepositoryInterface;
use App\Models\Entidad as EloquentEntidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EloquentEntidadRepository extends BaseRepository implements EntidadRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentEntidad::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return EntidadEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombre', 'like', "%{$search}%")
              ->orWhere('identificacion', 'like', "%{$search}%");
        });
    }

    protected function applyFilters($query, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($field === 'estado' && str_contains($value, ',')) {
                $values = array_map('trim', explode(',', $value));
                $values = array_map('strtolower', $values);
                $query->whereIn(DB::raw('LOWER(estado)'), $values);
            } else {
                parent::applyFilters($query, [$field => $value]);
            }
        }

        return $query;
    }
}
