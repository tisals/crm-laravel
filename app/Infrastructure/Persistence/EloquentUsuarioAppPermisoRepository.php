<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\UsuarioAppPermiso as UsuarioAppPermisoEntity;
use App\Domain\Repositories\UsuarioAppPermisoRepositoryInterface;
use App\Models\UsuarioAppPermiso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-backed implementation of the UsuarioAppPermiso repository.
 *
 * Routes reads through the `mysql_read` connection when available
 * (CQRS-Lite / Phase-2 read-replica split). Writes always hit the
 * master connection — see `BaseRepository` for the full contract.
 */
class EloquentUsuarioAppPermisoRepository extends BaseRepository implements UsuarioAppPermisoRepositoryInterface
{
    /** Use the read replica for SELECTs. */
    protected ?string $readConnection = 'mysql_read';

    protected function getModelClass(): string
    {
        return UsuarioAppPermiso::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return UsuarioAppPermisoEntity::fromArray($model->toArray());
    }

    public function findByUserAndApp(int $usuarioId, int $appId): array
    {
        return $this->newQuery()
            ->where('usuario_id', $usuarioId)
            ->where('app_id', $appId)
            ->orderBy('vista')
            ->get()
            ->map(fn (Model $m) => $this->mapModelToEntity($m))
            ->all();
    }

    public function sync(int $usuarioId, int $appId, array $vistas): array
    {
        // Normalize + dedupe so the same vista appearing twice in the
        // payload doesn't generate a duplicate-key error inside the txn.
        $vistas = array_values(array_unique(array_filter($vistas, fn ($v) => is_string($v) && $v !== '')));

        return DB::transaction(function () use ($usuarioId, $appId, $vistas) {
            // Delete existing rows for this (user, app).
            $this->newWriteQuery()
                ->where('usuario_id', $usuarioId)
                ->where('app_id', $appId)
                ->delete();

            // Insert the new set in bulk.
            $now = now();
            $rows = array_map(fn (string $vista) => [
                'usuario_id' => $usuarioId,
                'app_id' => $appId,
                'vista' => $vista,
                'created_at' => $now,
                'updated_at' => $now,
            ], $vistas);

            if ($rows !== []) {
                $this->newWriteQuery()->insert($rows);
            }

            return $this->findByUserAndApp($usuarioId, $appId);
        });
    }

    public function deleteByUserAndApp(int $usuarioId, int $appId): int
    {
        // Force-delete so the row is gone — these are scoped grants, not
        // domain records that benefit from soft-deletes here.
        return $this->newWriteQuery()
            ->where('usuario_id', $usuarioId)
            ->where('app_id', $appId)
            ->forceDelete();
    }

    public function bulkCreateDefaults(array $usuarioIds, int $appId, array $vistas): int
    {
        if ($usuarioIds === [] || $vistas === []) {
            return 0;
        }

        $now = now();
        $rows = [];
        foreach ($usuarioIds as $uid) {
            foreach ($vistas as $vista) {
                $rows[] = [
                    'usuario_id' => $uid,
                    'app_id' => $appId,
                    'vista' => $vista,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // INSERT IGNORE — idempotent: re-running the cascade won't blow up
        // on duplicate (user, app, vista). Returns affected row count.
        return DB::table('usuario_app_permisos')
            ->insertOrIgnore($rows);
    }
}
