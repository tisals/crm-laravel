<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Persona as PersonaEntity;
use App\Domain\Repositories\PersonaRepositoryInterface;
use App\Models\Persona as EloquentPersona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class EloquentPersonaRepository extends BaseRepository implements PersonaRepositoryInterface
{
    /**
     * Personas list is paginated and read-heavy. Route reads to the replica.
     */
    protected ?string $readConnection = 'mysql_read';

    /**
     * OI-6 (PR-I 6a.14): defer to `BaseRepository::newQuery()` so the
     * `isReadReplicaConfigured()` guard runs.
     *
     * PR-H discovered the same bug in `EloquentSeguimientoRepository`:
     * if `mysql_read` is not actually configured (dev / single-instance /
     * tests), the previous override forced a separate PDO connection that
     * could not see rows written inside a `RefreshDatabase` transaction.
     * The base class falls back to the default connection when the replica
     * points at the same host/port — fixing test isolation.
     */
    protected function newQuery()
    {
        return parent::newQuery();
    }

    protected function getModelClass(): string
    {
        return EloquentPersona::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return PersonaEntity::fromArray($model->toArray());
    }

    protected function applySearch($query, string $search)
    {
        // Commit 4 dropped `personas.email_principal` /
        // `personas.telefono_principal` — search now goes through
        // the shared `emails` / `telefonos` tables via `whereExists`.
        return $query->where(function ($q) use ($search) {
            $q->where('nombres', 'like', "%{$search}%")
                ->orWhere('apellidos', 'like', "%{$search}%")
                ->orWhere('identificacion_numero', 'like', "%{$search}%")
                ->orWhereExists(
                    fn ($sub) => $sub
                        ->from('emails')
                        ->whereColumn('emails.persona_id', 'personas.id')
                        ->where('emails.email', 'like', "%{$search}%")
                )
                ->orWhereExists(
                    fn ($sub) => $sub
                        ->from('telefonos')
                        ->whereColumn('telefonos.persona_id', 'personas.id')
                        ->where('telefonos.numero', 'like', "%{$search}%")
                );
        });
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = $this->newQuery();

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (! empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        $sortBy = $sortBy ?? 'created_at';
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'desc';

        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    public function findById(int $id): mixed
    {
        $model = $this->newQuery()->find($id);

        return $model ? $this->mapModelToEntity($model) : null;
    }
}
