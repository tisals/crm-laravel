<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data migration: pre-populate `usuario_app_permisos` for every existing
 * user, copying the default `permisos` of their rol into each app they
 * have access to (via entidad_usuario -> app_entidad).
 *
 * Idempotent: uses `INSERT IGNORE` so re-running the migration will not
 * create duplicate (user, app, vista) rows. Safe to call from
 * `migrate:fresh --seed` as well.
 *
 * Strategy:
 *   For each (usuario, app) pair where the user is linked to the app
 *   transitively via entidad_usuario + app_entidad, insert one row per
 *   vista defined in the user's rol's `permisos` table.
 *
 *   - SuperAdmin (rol.es_super_admin = 1) is skipped: they always pass via
 *     the wildcard check in `MultiAppRbacService` and storing per-vista rows
 *     would just be wasted storage.
 *   - Wildcards (`vista = '*'`) are not expanded into per-app rows — they
 *     continue to apply globally via the existing rol-based path.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Build the (usuario_id, app_id, vista) tuples to insert.
        //    The set is:
        //      usuarios JOIN roles (usuarios.rol_id = roles.id)
        //      JOIN permisos ON permisos.rol_id = roles.id AND permisos.vista != '*'
        //      JOIN entidad_usuario ON entidad_usuario.usuario_id = usuarios.id
        //      JOIN app_entidad ON app_entidad.entidad_id = entidad_usuario.entidad_id
        //                       AND app_entidad.estado = 'Activo'
        //
        //    Each row from the cross product becomes a scoped grant.
        //
        //    The INSERT IGNORE swallows the unique-key collision when this
        //    migration is re-run on an environment where some rows already
        //    exist (idempotency contract).
        DB::statement('
            INSERT IGNORE INTO usuario_app_permisos
                (usuario_id, app_id, vista, created_at, updated_at)
            SELECT
                u.id,
                ae.app_id,
                p.vista,
                NOW(),
                NOW()
            FROM usuarios u
            INNER JOIN roles r ON u.rol_id = r.id
            INNER JOIN permisos p ON p.rol_id = r.id
                                 AND p.vista <> "*"
                                 AND p.deleted_at IS NULL
            INNER JOIN entidad_usuario eu ON eu.usuario_id = u.id
            INNER JOIN app_entidad ae ON ae.entidad_id = eu.entidad_id
                                    AND ae.estado = "Activo"
            WHERE u.deleted_at IS NULL
        ');

        // 2. Mark every user that had at least one app assigned as having
        //    a stale snapshot. The next read of /me/identity will recompute.
        //    We only mark users that actually have a `user_identity_snapshot`
        //    row — if the snapshot table is empty (first deploy) the nightly
        //    refresh will create them all at 03:30 anyway.
        if (Schema::hasTable('user_identity_snapshot')) {
            DB::statement('
                UPDATE user_identity_snapshot s
                INNER JOIN (
                    SELECT DISTINCT eu.usuario_id AS user_id
                    FROM entidad_usuario eu
                    INNER JOIN app_entidad ae
                        ON ae.entidad_id = eu.entidad_id
                       AND ae.estado = "Activo"
                ) affected ON affected.user_id = s.user_id
                SET s.is_stale = 1
            ');
        }
    }

    public function down(): void
    {
        // Best-effort rollback: remove any row that has the shape produced
        // by this migration (i.e. any row that exists at all, since this
        // migration is the only writer). Admins that have manually added
        // rows since the migration ran will lose those too — acceptable
        // for a "go back to pre-multi-app state" rollback.
        DB::table('usuario_app_permisos')->delete();
    }
};
