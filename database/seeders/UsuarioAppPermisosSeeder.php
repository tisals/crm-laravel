<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UsuarioAppPermisosSeeder extends Seeder
{
    /**
     * Grant admin (usuario_id = 5) wildcard vista for all active apps.
     *
     * Per spec: one row per (admin user, app) with vista = '*'.
     * Uses updateOrInsert so it is idempotent — re-running the seeder
     * does not duplicate rows.
     */
    public function run(): void
    {
        $adminUserId = 5;
        $now = now();

        $apps = DB::table('apps')
            ->whereNull('deleted_at')
            ->get(['id', 'slug']);

        foreach ($apps as $app) {
            DB::table('usuario_app_permisos')->updateOrInsert(
                ['usuario_id' => $adminUserId, 'app_id' => $app->id],
                [
                    'vista' => '*',
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
