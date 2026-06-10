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
            RealDataSeeder::class,         // Datos CSV primero (crea entidades id=1,2 como Prospecto/Cliente)
            BrandPermissionsSeeder::class, // DESPUÉS: sobreescribe id=1 y id=2 como Propia (marca propia)
            PipelineSeeder::class,         // Pipelines y etapas predefinidas
        ]);
    }
}
