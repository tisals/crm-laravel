<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductoCsvSeeder extends Seeder
{
    use CsvSeederTrait;

    /**
     * Map numeric CSV values to brand names.
     * Key = valor en CSV, Value = nombre de linea_negocio a guardar.
     */
    private const LINEA_NEGOCIO_MAP = [
        '1' => 'Tecnoinnsoft',
        '2' => 'Deseguridad',
    ];

    public function run(): void
    {
        $csvFile = $this->csvPath('productos.csv');

        if (!file_exists($csvFile)) {
            $this->command->error("CSV file not found: {$csvFile}");
            return;
        }

        $rows = [];
        $skipped = 0;

        foreach ($this->parseCsv($csvFile) as $row) {
            $nombre = $row['producto_detalle'] ?? null;

            if (empty($nombre)) {
                $skipped++;
                continue;
            }

            $iva = $this->parsePercentage($row['iva'] ?? null);

            $csvLinea = $row['linea_negocio'] ?? null;
            $lineaNegocio = self::LINEA_NEGOCIO_MAP[$csvLinea] ?? $csvLinea;

            $rows[] = [
                'nombre' => $nombre,
                'linea_negocio' => $lineaNegocio,
                'iva' => $iva,
                'referencia' => $row['referencia'] ?? null,
                'descripcion' => $row['descripcion'] ?? null,
                'estado' => 'Activo',
                'tipo' => 'servicio',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            $this->command->warn("No valid rows found in CSV.");
            return;
        }

        // Upsert: inserta o actualiza por nombre para no romper FK references
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                DB::table('productos')->updateOrInsert(
                    ['nombre' => $row['nombre']],
                    $row
                );
            }
        });

        $this->command->info("Productos seeded: " . count($rows) . " rows ({$skipped} skipped).");
    }
}
