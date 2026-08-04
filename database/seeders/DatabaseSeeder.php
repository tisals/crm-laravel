<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Shared\Database\Seeders\SharedDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Phase 1: Foundation data
            RoleSeeder::class,
            PermisoSeeder::class,
            CiudadSeeder::class,
            RealDataSeeder::class,         // Datos CSV primero (crea entidades id=1,2 como Prospecto/Cliente)
            BrandPermissionsSeeder::class, // DESPUÉS: sobreescribe id=1 y id=2 como Propia (marca propia)
            PipelineSeeder::class,              // Pipelines y etapas predefinidas
            DodCapSeeder::class,                // Trunca a máx 10 ops y 10 contactos por entidad
            MergeDuplicateEntitiesSeeder::class, // FUSIONA duplicados generados durante OportunidadCsvSeeder

            // Phase 2: Multi-app-access catalog (verify-report-v2 finding #6)
            // Wires AppsSeeder + BrpRolesSeeder + UsuarioAppAssignmentsSeeder in
            // dependency order. Without this, a fresh `migrate:fresh --seed`
            // leaves the BRP integration env incomplete (0 apps, 0 BRP roles,
            // 0 pivot rows for the 4 canonical users).
            SharedDatabaseSeeder::class,
        ]);
    }
}
