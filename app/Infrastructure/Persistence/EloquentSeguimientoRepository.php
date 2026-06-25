<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Seguimiento as SeguimientoEntity;
use App\Domain\Repositories\SeguimientoRepositoryInterface;
use App\Models\Seguimiento as EloquentSeguimiento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EloquentSeguimientoRepository extends BaseRepository implements SeguimientoRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentSeguimiento::class;
    }

    protected function newQuery()
    {
        $modelClass = $this->getModelClass();

        return $modelClass::with(['autor', 'contacto', 'entidad', 'oportunidad']);
    }

    protected function applyFilters($query, array $filters): Builder
    {
        // Auto-filter by entidad_usuario for Comercial role
        $user = Auth::user();
        if ($user && $user->rol?->nombre === 'Comercial') {
            $query->whereIn('entidad_id', function ($q) use ($user) {
                $q->select('entidad_id')
                    ->from('entidad_usuario')
                    ->where('usuario_id', $user->id);
            });
        }

        return parent::applyFilters($query, $filters);
    }

    public function findForUser(int $userId, int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        $query = $this->newQuery();

        $this->scopeByUser($query, $userId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('notas', 'like', "%{$search}%")
                    ->orWhereHas('oportunidad', fn ($sq) => $sq->where('codigo', 'like', "%{$search}%"))
                    ->orWhereHas('contacto', function ($sq) use ($search) {
                        $sq->where('nombres', 'like', "%{$search}%")
                            ->orWhere('apellidos', 'like', "%{$search}%");
                    })
                    ->orWhereHas('entidad', fn ($sq) => $sq->where('nombre', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters)) {
            $this->applyFilters($query, $filters);
        }

        return $query->orderBy('fecha', 'desc')->orderBy('hora', 'desc')->paginate($perPage);
    }

    public function findCalendarForUser(int $userId, string $fechaDesde, string $fechaHasta, ?string $estado = null): Collection
    {
        $query = $this->newQuery();

        $this->scopeByUser($query, $userId);
        $query->whereBetween('fecha', [$fechaDesde, $fechaHasta]);

        if ($estado) {
            $query->where('estado', $estado);
        } else {
            // Default: only pending/scheduled (skip Completado/Cancelado for calendar noise)
            $query->whereIn('estado', ['Pendiente']);
        }

        return $query->orderBy('fecha')->orderBy('hora')->get();
    }

    /**
     * Apply entity-scope filtering based on user role.
     * Comercial → only entities mapped to user. Admin/SuperAdmin → no filter.
     */
    private function scopeByUser(Builder $query, int $userId): void
    {
        $userRolNombre = DB::table('roles')
            ->join('usuarios', 'usuarios.rol_id', '=', 'roles.id')
            ->where('usuarios.id', $userId)
            ->value('roles.nombre');

        if ($userRolNombre === 'Comercial') {
            $query->whereIn('entidad_id', function ($q) use ($userId) {
                $q->select('entidad_id')
                    ->from('entidad_usuario')
                    ->where('usuario_id', $userId);
            });
        }
        // Admin/SuperAdmin: no filter, see everything.
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return SeguimientoEntity::fromArray($model->toArray());
    }
}
