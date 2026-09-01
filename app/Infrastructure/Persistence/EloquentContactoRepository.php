<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Contacto as ContactoEntity;
use App\Domain\Repositories\ContactoRepositoryInterface;
use App\Models\Contacto as EloquentContacto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class EloquentContactoRepository extends BaseRepository implements ContactoRepositoryInterface
{
    /**
     * Contactos list is read-heavy and is hit on every dashboard page load.
     * Route reads to the replica.
     */
    protected ?string $readConnection = 'mysql_read';

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

    /**
     * Query that always brings the entidad name for display in frontend lists.
     *
     * Per commit fe99f70: `contacto.entidad_id` was dropped. The contacto →
     * entidad link now goes through the persona pivot:
     *   contacto.persona_id → persona.entidad_id (set on Persona rows)
     *
     * Routes to the read replica only when actually configured (different
     * host/port than the master). Otherwise uses the master — critical for
     * tests that use RefreshDatabase transaction isolation.
     */
    protected function newQueryWithEntidad()
    {
        $useReplica = $this->readConnection
            && $this->isReadReplicaConfigured($this->readConnection);

        $model = $useReplica
            ? (new EloquentContacto)->setConnection($this->readConnection)
            : new EloquentContacto;

        // Left join through persona → entidad so we still pick up the
        // entidad_nombre for display, while accommodating contactos that
        // lack a persona_id (orphans under the new model).
        return $model->newQuery()
            ->leftJoin('personas', 'contacto.persona_id', '=', 'personas.id')
            ->leftJoin('entidad', 'personas.entidad_id', '=', 'entidad.id')
            ->select('contacto.*', 'entidad.nombre as entidad_nombre');
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = $this->newQueryWithEntidad();

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (! empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        $sortBy = $sortBy ?? 'contacto.created_at';
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'desc';

        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    protected function applyFilters($query, array $filters): Builder
    {
        // Auto-filter by entidad_persona (via usuarios.persona_id) for Comercial role.
        $user = Auth::user();
        if ($user && $user->rol?->nombre === 'Comercial') {
            $query->whereIn('contacto.persona_id', function ($q) use ($user) {
                $q->select('ep.persona_id')
                    ->from('entidad_persona as ep')
                    ->where('ep.entidad_id', function ($sq) use ($user) {
                        // Match the user's entities via the new pivot.
                        $sq->select('entidad_id')
                            ->from('entidad_persona')
                            ->join('usuarios', 'usuarios.persona_id', '=', 'entidad_persona.persona_id')
                            ->where('usuarios.id', $user->id);
                    });
            });
        }

        return parent::applyFilters($query, $filters);
    }

    public function findById(int $id): mixed
    {
        $model = $this->newQueryWithEntidad()->find($id);

        return $model ? $this->mapModelToEntity($model) : null;
    }
}
