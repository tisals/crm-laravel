<?php

namespace App\Application\UseCases\App;

use App\Application\UseCases\Me\RefreshUserIdentitySnapshotUseCase;
use App\Domain\Repositories\UsuarioAppPermisoRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssignAppToEntidadUseCase
{
    public function __construct(
        private UsuarioAppPermisoRepositoryInterface $permisoRepository,
        private RefreshUserIdentitySnapshotUseCase $refreshIdentitySnapshot,
    ) {}

    /**
     * Assigns an app to an entity. Idempotent: if the assignment exists,
     * updates the metadata (estado, fechas, notas) instead of duplicating.
     *
     * Cascade (the multi-app behavior — see spec/app-scoped-permissions):
     *   After the assignment is written, for every user that belongs to
     *   the entity via `entidad_usuario`, we propagate default scoped
     *   permissions into `usuario_app_permisos`. The defaults come from
     *   the user's rol's `permisos` table (excluding the wildcard '*').
     *
     *   Affected users' identity snapshots are marked stale so the next
     *   /me/identity read recomputes.
     *
     * Returns the pivot record id.
     */
    public function execute(int $appId, int $entidadId, array $metadata = []): int
    {
        $defaults = [
            'fecha_contrato' => now()->toDateString(),
            'fecha_vencimiento' => null,
            'estado' => 'Activo',
            'notas' => null,
            'created_by' => null,
        ];

        $data = array_merge($defaults, array_intersect_key($metadata, $defaults));

        // 1. Update or insert the pivot.
        $existing = DB::table('app_entidad')
            ->where('app_id', $appId)
            ->where('entidad_id', $entidadId)
            ->first();

        $pivotId = null;
        $isNew = false;

        if ($existing) {
            DB::table('app_entidad')
                ->where('id', $existing->id)
                ->update(array_merge($data, [
                    'updated_at' => now(),
                ]));
            $pivotId = $existing->id;
        } else {
            $pivotId = DB::table('app_entidad')->insertGetId(array_merge($data, [
                'app_id' => $appId,
                'entidad_id' => $entidadId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $isNew = true;
        }

        // 2. Cascade permissions only when the pivot is new (or re-activated).
        //    If the pivot is just being updated, we don't want to clobber
        //    admin-granted per-user overrides that may have been set since.
        //    Re-activation (estado → 'Activo') is treated as a cascade
        //    because previous rows were likely deleted when the app was
        //    removed; if not, the cascade is idempotent (INSERT IGNORE).
        $shouldCascade = $isNew || $data['estado'] === 'Activo';

        if ($shouldCascade) {
            $this->cascade($appId, $entidadId);
        }

        return (int) $pivotId;
    }

    /**
     * For every user on the entity, copy the user's rol default permisos
     * (excluding the wildcard '*') into `usuario_app_permisos` for this
     * app, then mark the affected users' snapshot rows as stale.
     */
    private function cascade(int $appId, int $entidadId): void
    {
        // Collect (usuario_id, rol_id) tuples for users in this entidad.
        $userRows = DB::table('entidad_usuario as eu')
            ->join('usuarios as u', 'eu.usuario_id', '=', 'u.id')
            ->where('eu.entidad_id', $entidadId)
            ->whereNull('u.deleted_at')
            ->select('u.id as usuario_id', 'u.rol_id')
            ->get();

        if ($userRows->isEmpty()) {
            return;
        }

        // Group by rol_id so we look up default permisos once per rol.
        $byRol = [];
        foreach ($userRows as $row) {
            $byRol[(int) $row->rol_id][] = (int) $row->usuario_id;
        }

        $affectedUsers = [];
        foreach ($byRol as $rolId => $userIds) {
            $vistas = DB::table('permisos')
                ->where('rol_id', $rolId)
                ->where('vista', '!=', '*')
                ->whereNull('deleted_at')
                ->pluck('vista')
                ->all();

            if ($vistas === []) {
                // Rol with no specific permisos (e.g. an admin-only rol
                // that grants access only via the wildcard) — nothing to
                // cascade.
                continue;
            }

            $inserted = $this->permisoRepository->bulkCreateDefaults(
                $userIds,
                $appId,
                $vistas
            );

            Log::info('rbac.cascade.assigned', [
                'app_id' => $appId,
                'rol_id' => $rolId,
                'users' => count($userIds),
                'vistas' => count($vistas),
                'rows_inserted' => $inserted,
            ]);

            foreach ($userIds as $uid) {
                $affectedUsers[$uid] = true;
            }
        }

        // 3. Mark affected users' snapshots stale so the next /me/identity
        //    read recomputes.
        $userIds = array_keys($affectedUsers);
        if ($userIds !== []) {
            $this->refreshIdentitySnapshot->invalidate($userIds);
        }
    }
}
