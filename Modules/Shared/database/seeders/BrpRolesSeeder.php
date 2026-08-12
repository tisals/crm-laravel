<?php

namespace Modules\Shared\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrpRolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['slug' => 'brp-admin',     'nombre' => 'BRP Admin',      'es_super_admin' => false, 'estado' => 'Activo'],
            ['slug' => 'brp-lider',     'nombre' => 'BRP Líder',      'es_super_admin' => false, 'estado' => 'Activo'],
            ['slug' => 'brp-psicologo', 'nombre' => 'BRP Psicólogo', 'es_super_admin' => false, 'estado' => 'Activo'],
        ];

        foreach ($roles as $rol) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $rol['slug']],
                $rol + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
