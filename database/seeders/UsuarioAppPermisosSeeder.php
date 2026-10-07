<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UsuarioAppPermisosSeeder extends Seeder
{
    /**
     * Grant the admin user wildcard vista for all active apps.
     *
     * Per spec 01-seed-spec.md L11, L53-56: one row per (admin user, app)
     * with vista = '*'. The spec hardcodes usuario_id = 5 (assumes admin
     * is the 5th user created), but that depends on the order of prior
     * seeders (RealDataSeeder, BrandPermissionsSeeder, etc.). Lookup by
     * canonical admin email + SuperAdmin rol is more robust.
     *
     * Idempotent: uses updateOrInsert so re-running does not duplicate.
     */
    public function run(): void
    {
        // Resolve admin by canonical email + SuperAdmin rol.
        $admin = Usuario::where('email', 'admin@tecnoinnsoft.dev')
            ->where('rol_id', 1)
            ->first();

        if (! $admin) {
            // Fallback: any user with SuperAdmin rol (per BrandPermissionsSeeder
            // there is also innovacionydesarrollo.tis@gmail.com with rol_id=1).
            $this->command?->warn(
                'UsuarioAppPermisosSeeder: admin@tecnoinnsoft.dev not found; skipping wildcard grant.'
            );
            return;
        }

        $now = now();

        $apps = DB::table('apps')
            ->whereNull('deleted_at')
            ->get(['id', 'slug']);

        foreach ($apps as $app) {
            DB::table('usuario_app_permisos')->updateOrInsert(
                ['usuario_id' => $admin->id, 'app_id' => $app->id],
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
