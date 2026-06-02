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

        $this->command->info('Assigning entities equitatively among the 4 commercial / admin users...');
        $emails = [
            'gestorcomercial.tis@gmail.com',
            'direccion.tis@gmail.com',
            'innovacionydesarrollo.tis@gmail.com',
            'servicioalcliente.tis@gmail.com'
        ];
        $userIds = \Illuminate\Support\Facades\DB::table('usuarios')
            ->whereIn('email', $emails)
            ->orderBy('id')
            ->pluck('id')
            ->toArray();

        if (count($userIds) === 4) {
            $entidadIds = \Illuminate\Support\Facades\DB::table('entidad')
                ->orderBy('id')
                ->pluck('id')
                ->toArray();

            $totalEntidades = count($entidadIds);
            $chunkSize = (int) ceil($totalEntidades / 4);
            $chunks = array_chunk($entidadIds, $chunkSize);

            \Illuminate\Support\Facades\DB::table('entidad_usuario')->delete(); // clean pivot

            $insertData = [];
            foreach ($userIds as $index => $userId) {
                if (isset($chunks[$index])) {
                    foreach ($chunks[$index] as $entidadId) {
                        $insertData[] = [
                            'usuario_id' => $userId,
                            'entidad_id' => $entidadId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }
            
            // Insert in chunks of 500 rows to prevent DB packet size limits
            foreach (array_chunk($insertData, 500) as $chunk) {
                \Illuminate\Support\Facades\DB::table('entidad_usuario')->insert($chunk);
            }
            
            $this->command->info("✅ Assigned {$totalEntidades} entities equitatively among 4 users (" . count($insertData) . " assignments).");
        } else {
            $this->command->error("Could not find the 4 target users (found " . count($userIds) . "). Skipping assignment.");
        }

        $this->command->info('Real data import complete.');
    }
}
