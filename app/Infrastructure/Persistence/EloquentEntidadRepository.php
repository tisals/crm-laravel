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

    /**
     * The entidad list view is read-heavy (paginated, with counts/relations).
     * Route reads to the replica; writes still go through the master
     * via BaseRepository::create/update/delete.
     */
    protected ?string $readConnection = 'mysql_read';

    protected function newQuery()
    {
        // Defer to BaseRepository::newQuery() so the isReadReplicaConfigured()
        // guard runs. The previous override forced mysql_read even when the
        // replica pointed at the same host/port as the master (dev / tests),
        // which broke test isolation: tests using RefreshDatabase wrap writes
        // in a transaction on the default connection, and a separate
        // mysql_read connection cannot see uncommitted rows. The base class
        // falls back to the default connection when the replica is not
        // actually configured, which fixes this.
        $query = parent::newQuery();

        return $query
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
        // Auto-filter by entidad_persona (via usuarios.persona_id FK) for Comercial role.
        // Per commit fe99f70 + migration 000003.
        $user = Auth::user();
        if ($user && $user->rol?->nombre === 'Comercial') {
            $query->whereIn('id', function ($q) use ($user) {
                $q->select('ep.entidad_id')
                    ->from('entidad_persona as ep')
                    ->join('usuarios as u', 'u.persona_id', '=', 'ep.persona_id')
                    ->where('u.id', $user->id);
            });
        }

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($field === 'estado') {
                // Commit 8: `entidad.estado` was dropped. The legacy
                // ENUM values (cliente / prospecto / propia / proveedor)
                // now live on the `entidad_relacion` pivot. Translate
                // the filter to a `WHERE EXISTS` against the pivot so
                // the API contract (?estado=cliente,prospecto) stays
                // consistent for any caller that was relying on it.
                $values = array_map('trim', explode(',', (string) $value));
                $values = array_map('strtolower', $values);

                // Map legacy derived states ('activo', 'inactivo') onto
                // a pivot existence check. The pivot's `effective_to
                // IS NULL` is the canonical "currently active" flag —
                // mirroring `Entidad::getEstadoAttribute()`.
                $pivotTipos = array_values(array_intersect($values, [
                    'cliente', 'prospecto', 'propia', 'proveedor',
                ]));
                $wantsActivo = in_array('activo', $values, true);
                $wantsInactivo = in_array('inactivo', $values, true);

                if (empty($pivotTipos) && ! $wantsActivo && ! $wantsInactivo) {
                    // No recognized values — skip the filter rather
                    // than return an empty result set.
                    continue;
                }

                // Build a single OR-of-pivot-clauses subquery. Each
                // clause is a `WHERE EXISTS` so we get one roundtrip
                // and the OR-of-clauses shape stays readable.
                $query->where(function ($q) use ($pivotTipos, $wantsActivo, $wantsInactivo) {
                    $first = true;

                    if (! empty($pivotTipos)) {
                        $first
                            ? $q->whereExists(function ($sub) use ($pivotTipos) {
                                $sub->select(DB::raw(1))
                                    ->from('entidad_relacion as er')
                                    ->whereColumn('er.entidad_id', 'entidad.id')
                                    ->whereIn('er.tipo_relacion', $pivotTipos)
                                    ->whereNull('er.effective_to');
                            })
                            : $q->orWhereExists(function ($sub) use ($pivotTipos) {
                                $sub->select(DB::raw(1))
                                    ->from('entidad_relacion as er')
                                    ->whereColumn('er.entidad_id', 'entidad.id')
                                    ->whereIn('er.tipo_relacion', $pivotTipos)
                                    ->whereNull('er.effective_to');
                            });
                        $first = false;
                    }

                    if ($wantsActivo) {
                        $first
                            ? $q->whereExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('entidad_relacion as er')
                                    ->whereColumn('er.entidad_id', 'entidad.id')
                                    ->whereNull('er.effective_to');
                            })
                            : $q->orWhereExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('entidad_relacion as er')
                                    ->whereColumn('er.entidad_id', 'entidad.id')
                                    ->whereNull('er.effective_to');
                            });
                        $first = false;
                    }

                    if ($wantsInactivo) {
                        $first
                            ? $q->whereNotExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('entidad_relacion as er')
                                    ->whereColumn('er.entidad_id', 'entidad.id')
                                    ->whereNull('er.effective_to');
                            })
                            : $q->orWhereNotExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('entidad_relacion as er')
                                    ->whereColumn('er.entidad_id', 'entidad.id')
                                    ->whereNull('er.effective_to');
                            });
                    }
                });
            } else {
                parent::applyFilters($query, [$field => $value]);
            }
        }

        return $query;
    }
}
