<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Entidad as EntidadEntity;
use App\Domain\Repositories\EntidadRepositoryInterface;
use App\Models\Entidad as EloquentEntidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EloquentEntidadRepository extends BaseRepository implements EntidadRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentEntidad::class;
    }

    protected function newQuery()
    {
        return EloquentEntidad::query()
            ->withCount(['contactos', 'oportunidades'])
            ->with(['usuarios', 'ciudad']);
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        $data = $model->toArray();
        $data['contactos_count'] = $model->contactos_count ?? 0;
        $data['oportunidades_count'] = $model->oportunidades_count ?? 0;
        $data['comercial_asignado'] = $model->usuarios->first() ? $model->usuarios->first()->nombre : 'Sin asignar';
        $data['ciudad_nombre'] = $model->ciudad ? $model->ciudad->nombre : ($model->ciudad_cod ?? '');

        return EntidadEntity::fromArray($data);
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('nombre', 'like', "%{$search}%")
                ->orWhere('identificacion', 'like', "%{$search}%");
        });
    }

    protected function applyFilters($query, array $filters): Builder
    {
        // Auto-filter by entidad_usuario for Comercial role
        $user = Auth::user();
        if ($user && $user->rol?->nombre === 'Comercial') {
            $query->whereIn('id', function ($q) use ($user) {
                $q->select('entidad_id')
                    ->from('entidad_usuario')
                    ->where('usuario_id', $user->id);
            });
        }

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
