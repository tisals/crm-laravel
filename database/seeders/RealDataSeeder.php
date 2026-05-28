<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Master seeder that imports real data from CSV files (Entidades, Contactos, Productos, etc.).
 *
 * Dependency order:
 *  1. MaestroSeeder        — maestros table (reference data)
 *  2. CiudadSeeder         — ciudades table
 *  3. EntidadCsvSeeder     — entidad table (depends on ciudades)
 *  4. ContactoCsvSeeder    — contacto table (depends on entidad)
 *  5. ProductoCsvSeeder    — productos table
 *  6. OportunidadCsvSeeder — oportunidades (upsert by codigo)
 *  7. DetalleOportunidadCsvSeeder — detalles reales desde CSV (reemplaza sintéticos)
 */
class RealDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Starting real data import from CSV files...');

        $this->callWith(MaestroSeeder::class, []);
        $this->command->info('✅ Maestros seeded.');

        $this->callWith(CiudadSeeder::class, []);
        $this->command->info('✅ Ciudades seeded.');

        $this->callWith(EntidadCsvSeeder::class, []);
        $this->command->info('✅ Entidades seeded.');

        $this->callWith(ContactoCsvSeeder::class, []);
        $this->command->info('✅ Contactos seeded.');

        $this->callWith(ProductoCsvSeeder::class, []);
        $this->command->info('✅ Productos seeded.');

        $this->callWith(OportunidadCsvSeeder::class, []);
        $this->command->info('✅ Oportunidades seeded.');

        $this->callWith(DetalleOportunidadCsvSeeder::class, []);
        $this->command->info('✅ Detalles seeded.');

        $this->callWith(SeguimientoCsvSeeder::class, []);
        $this->command->info('✅ Seguimientos seeded.');

        $this->command->info('Real data import complete.');
    }
}
