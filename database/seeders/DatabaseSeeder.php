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
            AppsCatalogSeeder::class,           // Catálogo de apps (minerva, mercurio, fama, wp-plugin, concordia, janus, numeria, tempus, vesta, labor, sailus)
            RealDataSeeder::class,         // Datos CSV primero (crea entidades id=1,2 como Prospecto/Cliente)
            BrandPermissionsSeeder::class, // DESPUÉS: sobreescribe id=1 y id=2 como Propia (marca propia)
            MultiTenantPivotSeeder::class, // DESPUÉS: app_entidad + usuario_app_permisos para que /me/apps y /me/identity tengan datos
            PipelineSeeder::class,              // Pipelines y etapas predefinidas
            DodCapSeeder::class,                // Trunca a máx 10 ops y 10 contactos por entidad
            MergeDuplicateEntitiesSeeder::class, // FUSIONA duplicados generados durante OportunidadCsvSeeder
        ]);

        // PR-D — SAIlus Agent profile binding (REQ-HPBN-002, REQ-HPBN-003, formerly Hermes).
        // Opt-in via config flag so production migrations remain lean.
        // Local/dev workflows can flip SAILUS_AUTO_SEED=true.
        if (config('sailus.auto_seed') === true) {
            $this->call(SailusAgentSeeder::class);
        }
    }
}
