<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermisoSeeder::class,
            CiudadSeeder::class,
            BrandPermissionsSeeder::class,
            RealDataSeeder::class,   // Entidades, Contactos, Productos y Maestros reales desde CSV
        ]);
    }
}
