<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Oportunidad as OportunidadEntity;
use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Models\Oportunidad as EloquentOportunidad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentOportunidadRepository extends BaseRepository implements OportunidadRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentOportunidad::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return OportunidadEntity::fromArray($model->toArray());
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = $this->newQuery();

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (!empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        $sortBy = $sortBy ?? 'created_at';
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'desc';

        return match ($sortBy) {
            'entidad' => $query->orderByRaw(
                "(SELECT nombre FROM entidad WHERE entidad.id = oportunidad.entidad_id) {$sortOrder}"
            )->paginate($perPage),
            'valor' => $query->orderByRaw(
                "(SELECT COALESCE(SUM(vr_total), 0) FROM detalle_oportunidad WHERE detalle_oportunidad.oportunidad_id = oportunidad.id) {$sortOrder}"
            )->paginate($perPage),
            default => $query->orderBy($sortBy, $sortOrder)->paginate($perPage),
        };
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('codigo', 'like', "%{$search}%")
              ->orWhereHas('entidad', function ($sq) use ($search) {
                  $sq->where('nombre', 'like', "%{$search}%");
              });
        });
    }

    public function getNextCodigo(): string
    {
        $semestre = (int) date('n') <= 6 ? 1 : 2;
        $year = date('Y');
        $prefix = "GC-{$semestre}-{$year}-";

        $last = EloquentOportunidad::withTrashed()
            ->where('codigo', 'like', "{$prefix}%")
            ->orderBy('codigo', 'desc')
            ->lockForUpdate()
            ->first();

        if (!$last) {
            return $prefix . '001';
        }

        $lastConsec = (int) substr($last->codigo, strlen($prefix));
        return $prefix . str_pad((string) ($lastConsec + 1), 3, '0', STR_PAD_LEFT);
    }

    protected function applyFilters($query, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            match ($field) {
                'fecha_desde' => $query->whereDate('fecha', '>=', $value),
                'fecha_hasta' => $query->whereDate('fecha', '<=', $value),
                'codigo' => $query->where('codigo', 'like', "%{$value}%"),
                'producto_id' => $query->whereHas('detalles', fn($q) => $q->where('producto_id', $value)),
                default => $query->where($field, $value),
            };
        }

        return $query;
    }
}
