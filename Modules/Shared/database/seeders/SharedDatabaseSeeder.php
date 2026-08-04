<?php

namespace Modules\Shared\Database\Seeders;

use Illuminate\Database\Seeder;

class SharedDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AppsSeeder::class,
            BrpRolesSeeder::class,
            UsuarioAppAssignmentsSeeder::class,
        ]);
    }
}
