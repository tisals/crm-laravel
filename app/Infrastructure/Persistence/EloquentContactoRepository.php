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
        $data = $model->toArray();

        // Per commit fe99f70: `contacto.entidad_id` was dropped. Resolve
        // it from the `entidad_persona` pivot so downstream callers see
        // the legacy field populated.
        if (! isset($data['entidad_id']) && ! empty($data['persona_id'])) {
            $data['entidad_id'] = \DB::table('entidad_persona')
                ->where('persona_id', $data['persona_id'])
                ->orderBy('entidad_id')
                ->value('entidad_id');
        }

        return ContactoEntity::fromArray($data);
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

    /**
     * Override the base create() to honor the legacy `entidad_id` input.
     *
     * Per commit fe99f70: `contacto.entidad_id` was dropped. The contacto's
     * entidad binding now lives in the `entidad_persona` pivot, keyed on
     * the contacto's persona_id. When the caller supplies `entidad_id`
     * (the existing API contract), we:
     *   1. Strip `entidad_id` from the contact insert payload.
     *   2. Ensure a `personas` row exists for this contacto (backfilled
     *      from `email_contacto` if missing).
     *   3. Insert the contacto with the new `persona_id`.
     *   4. Insert the `entidad_persona` pivot row.
     *
     * This keeps the API contract intact while the underlying schema is
     * mediated by the pivot.
     */
    public function create(array $data): mixed
    {
        $entidadId = $data['entidad_id'] ?? null;
        unset($data['entidad_id']);

        // 1. Resolve persona_id from email_contacto (backfill on demand).
        if (! empty($data['email_contacto']) && empty($data['persona_id'])) {
            $personaId = \DB::table('personas')
                ->where('email_principal', $data['email_contacto'])
                ->value('id');

            if (! $personaId) {
                $personaId = \DB::table('personas')->insertGetId([
                    'email_principal' => $data['email_contacto'],
                    'nombres' => $data['nombres'] ?? null,
                    'apellidos' => $data['apellidos'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $data['persona_id'] = $personaId;
        }

        // 2. Mirror the legacy `entidad_id` into the pivot BEFORE
        //    mapping to the entity — the entity's `entidad_id` field is
        //    resolved from the pivot in `mapModelToEntity()`.
        if ($entidadId && ! empty($data['persona_id'])) {
            \DB::table('entidad_persona')->insert([
                'persona_id' => (int) $data['persona_id'],
                'entidad_id' => (int) $entidadId,
                'categoria' => 'asignacion',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Create the contacto via the base repository (writes to master).
        return parent::create($data);
    }

    public function update(int $id, array $data): mixed
    {
        $entidadId = $data['entidad_id'] ?? null;
        unset($data['entidad_id']);

        $entity = parent::update($id, $data);

        if ($entity && $entidadId !== null && isset($entity->persona_id)) {
            // Mirror the legacy behavior: write the pivot. Existing pivot
            // rows for the persona are removed first so the contacto
            // binds to exactly one entidad at the application layer.
            \DB::transaction(function () use ($entity, $entidadId) {
                \DB::table('entidad_persona')
                    ->where('persona_id', $entity->persona_id)
                    ->delete();
                if ($entidadId) {
                    \DB::table('entidad_persona')->insert([
                        'persona_id' => (int) $entity->persona_id,
                        'entidad_id' => (int) $entidadId,
                        'categoria' => 'asignacion',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        }

        return $entity;
    }
}
