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
            RealDataSeeder::class,   // Entidades, Contactos, Productos y Maestros reales desde CSV
            BrandPermissionsSeeder::class, // Vincula los usuarios principales a las marcas propias (id 1 y 2)
        ]);
    }
}
