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
            AppsCatalogSeeder::class,              // Catálogo de apps (11 canónicos, soft-delete 7 legacy)
            RealDataSeeder::class,                 // Datos CSV primero (crea entidades id=1,2 como Prospecto/Cliente)
            BrandPermissionsSeeder::class,         // DESPUÉS: sobreescribe id=1 y id=2 como Propia (marca propia)
            AppEntidadSeeder::class,               // Contratos app ↔ entidad (necesario para que /me/identity devuelva apps)
            PipelineSeeder::class,                // Pipelines y etapas predefinidas
            DodCapSeeder::class,                   // Trunca a máx 10 ops y 10 contactos por entidad
            MergeDuplicateEntitiesSeeder::class,   // FUSIONA duplicados generados durante OportunidadCsvSeeder
            UsuarioAppPermisosSeeder::class,       // Admin wildcard para las 11 apps (janus-apps-canonicalization T1.1)
        ]);
    }
}
