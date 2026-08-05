<?php

namespace App\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class BaseRepository
{
    /**
     * Connection name to use for READ operations (paginate, findById, etc).
     * Subclasses override this to route reads to a replica.
     * WRITES (create/update/delete) always go to the master connection
     * regardless of this setting — see newWriteQuery().
     */
    protected ?string $readConnection = null;

    abstract protected function getModelClass(): string;

    abstract protected function mapModelToEntity(Model $model): mixed;

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

    public function create(array $data): mixed
    {
        // Writes always go to master — never to the read replica.
        $model = $this->newWriteQuery()->create($data);

        return $this->mapModelToEntity($model);
    }

    public function update(int $id, array $data): mixed
    {
        // Read on the replica (fast), write on master (correct).
        $model = $this->newQuery()->find($id);

        if (! $model) {
            return null;
        }

        // Set the model to use the master connection for the update.
        $model->setConnection($this->getMasterConnectionName());
        $model->update($data);

        return $this->mapModelToEntity($model->fresh());
    }

    public function delete(int $id): bool
    {
        $model = $this->newQuery()->find($id);

        if (! $model) {
            return false;
        }

        $model->setConnection($this->getMasterConnectionName());

        return $model->delete();
    }

    /**
     * Build a query that reads from the read replica (or master if no
     * replica is actually configured). Used by paginate, findById, and
     * any read-only methods.
     *
     * A "configured" replica means the `mysql_read` connection points
     * to a different host or port than the default `mysql` connection.
     * When they point to the same place (dev / single-instance / tests),
     * the read falls back to the master so that:
     *   - We don't have two PDO connections in dev (saves memory)
     *   - Tests using RefreshDatabase (transaction-isolated) work
     *     because both reads and writes hit the same connection.
     */
    protected function newQuery()
    {
        $modelClass = $this->getModelClass();

        if ($this->readConnection && $this->isReadReplicaConfigured($this->readConnection)) {
            $model = new $modelClass;
            $model->setConnection($this->readConnection);

            return $model->newQuery();
        }

        return $modelClass::query();
    }

    /**
     * Returns true if the named connection is actually pointing to a
     * different host/port than the default `mysql` connection.
     * Compares the runtime config (after env resolution).
     */
    protected function isReadReplicaConfigured(string $connectionName): bool
    {
        $replica = config("database.connections.{$connectionName}");
        $master = config('database.connections.mysql');

        if (! $replica || ! $master) {
            return false;
        }

        $replicaHost = $replica['host'] ?? null;
        $masterHost = $master['host'] ?? null;
        $replicaPort = $replica['port'] ?? null;
        $masterPort = $master['port'] ?? null;

        return ($replicaHost !== $masterHost) || ($replicaPort !== $masterPort);
    }

    /**
     * Build a query that writes to the master. Used by create/update/delete.
     */
    protected function newWriteQuery()
    {
        $modelClass = $this->getModelClass();

        return $modelClass::query();
    }

    protected function getMasterConnectionName(): string
    {
        // Always use the model's default connection for writes — that's the
        // master (the one configured as DB_CONNECTION).
        return $this->getModelClass()::query()->getModel()->getConnectionName()
            ?? config('database.default');
    }

    protected function applySearch($query, string $search)
    {
        return $query->where('nombre', 'like', "%{$search}%");
    }

    protected function applyFilters($query, array $filters)
    {
        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                $query->where($field, $value);
            }
        }

        return $query;
    }
}
