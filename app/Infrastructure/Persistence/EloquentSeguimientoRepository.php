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

    /**
     * Calendar and seguimientos lists are read-heavy with eager-loaded
     * relations. Route reads to the replica; writes still go through
     * the master via BaseRepository::create/update/delete.
     */
    protected ?string $readConnection = 'mysql_read';

    protected function newQuery()
    {
        // PR-H: defer to BaseRepository::newQuery() so the
        // isReadReplicaConfigured() guard runs. The previous override
        // forced mysql_read even when the replica pointed at the same
        // host/port as the master (dev / single-instance / tests),
        // which broke test isolation: tests using RefreshDatabase wrap
        // writes in a transaction on the default connection, and a
        // separate mysql_read connection cannot see uncommitted rows.
        // The base class falls back to the default connection when the
        // replica is not actually configured, which fixes this.
        $query = parent::newQuery();

        // PR-H (Phase 5b - REQ-SEG-004): swap `contacto` for `persona`
        // in the eager-load set. The `contacto()` relation was removed
        // from the Eloquent model in PR-H; loading it here would throw.
        return $query->with(['autor', 'persona', 'entidad', 'oportunidad']);
    }

    protected function applyFilters($query, array $filters): Builder
    {
        // Auto-filter by entidad_usuario for Comercial role (HTTP context).
        // Note: programmatic callers (findForUser) use scopeByUser() instead.
        $user = Auth::user();
        if ($user && $user->rol?->nombre === 'Comercial') {
            $query->whereIn('entidad_id', function ($q) use ($user) {
                $q->select('entidad_id')
                    ->from('entidad_usuario')
                    ->where('usuario_id', $user->id);
            });
        }

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            match ($field) {
                'fecha_desde' => $query->whereDate('fecha', '>=', $value),
                'fecha_hasta' => $query->whereDate('fecha', '<=', $value),
                'oportunidad_id' => $query->where('oportunidad_id', $value),
                // PR-H: replace `contacto_id` filter with `persona_id`.
                // The legacy filter key is rejected here so callers
                // update their integration.
                'persona_id' => $query->where('persona_id', $value),
                'entidad_id' => $query->where('entidad_id', $value),
                'tipo' => $query->where('tipo', $value),
                'estado' => $query->where('estado', $value),
                default => null,  // ignore unknown filter keys
            };
        }

        return $query;
    }

    public function findForUser(int $userId, int $perPage = 15, ?string $search = null, array $filters = []): LengthAwarePaginator
    {
        $query = $this->newQuery();

        $this->scopeByUser($query, $userId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('notas', 'like', "%{$search}%")
                    ->orWhereHas('oportunidad', fn ($sq) => $sq->where('codigo', 'like', "%{$search}%"))
                    ->orWhereHas('persona', function ($sq) use ($search) {
                        // PR-H: search by persona nombres/apellidos
                        // instead of the (gone) contacto relation.
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
     * Comercial -> only entities mapped to user. Admin/SuperAdmin -> no filter.
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
