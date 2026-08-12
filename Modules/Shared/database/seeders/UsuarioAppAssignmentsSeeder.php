<?php

namespace Modules\Shared\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UsuarioAppAssignmentsSeeder extends Seeder
{
    /**
     * Canonical assignments. Email → {apps[], rol}.
     * Lookup by email (NOT id) so the seeder is resilient to changes in user IDs.
     */
    private const ASSIGNMENTS = [
        'admin@tecnoinnsoft.dev' => [
            'apps' => ['crm', 'sailus', 'marketing', 'wp-plugin', 'la-llave', 'brp'],
            'rol' => 'super-admin',
        ],
        'innovacionydesarrollo.tis@gmail.com' => [
            'apps' => ['crm'],
            'rol' => 'comercial',
        ],
        'servicioalcliente.tis@gmail.com' => [
            'apps' => ['crm', 'brp'],
            'rol' => 'operaciones',
        ],
        'direccion.tis@gmail.com' => [
            'apps' => ['crm', 'marketing'],
            'rol' => 'super-admin',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::ASSIGNMENTS as $email => $config) {
                $user = DB::table('usuarios')->where('email', $email)->first();
                if (! $user) {
                    Log::warning('UsuarioAppAssignmentsSeeder: user not found', compact('email'));
                    continue;
                }

                $rol = DB::table('roles')->where('slug', $config['rol'])->first();
                if (! $rol) {
                    Log::warning('UsuarioAppAssignmentsSeeder: rol not found', ['email' => $email, 'rol_slug' => $config['rol']]);
                    continue;
                }

                foreach ($config['apps'] as $appSlug) {
                    $app = DB::table('apps')->where('slug', $appSlug)->first();
                    if (! $app) {
                        Log::warning('UsuarioAppAssignmentsSeeder: app not found', ['email' => $email, 'app_slug' => $appSlug]);
                        continue;
                    }

                    DB::table('usuario_app')->updateOrInsert(
                        ['usuario_id' => $user->id, 'app_id' => $app->id],
                        ['rol_id' => $rol->id, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        });
    }
}
