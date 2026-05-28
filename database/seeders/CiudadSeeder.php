<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CiudadSeeder extends Seeder
{
    use CsvSeederTrait;

    public function run(): void
    {
        $csvFile = $this->csvPath('ciudades.csv');

        if (!file_exists($csvFile)) {
            $this->command->error("CSV file not found: {$csvFile}");
            return;
        }

        $rows = [];
        $skipped = 0;

        foreach ($this->parseCsv($csvFile) as $row) {
            $code = $row['cod_municipio'] ?? null;

            if (empty($code)) {
                $skipped++;
                continue;
            }

            $code = $this->padCode($code, 5);
            $nombre = $row['municipio'] ?? null;

            if (empty($nombre)) {
                $skipped++;
                continue;
            }

            $rows[] = [
                'cod_municipio' => $code,
                'nombre' => mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8'),
                'departamento' => mb_convert_case($row['departamento'] ?? '', MB_CASE_TITLE, 'UTF-8'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            $this->command->warn("No valid rows found in CSV.");
            return;
        }

        // Use a single transaction with chunked upserts for performance
        DB::transaction(function () use ($rows) {
            $chunkSize = 200;
            $chunks = array_chunk($rows, $chunkSize);

            foreach ($chunks as $chunk) {
                $codes = array_column($chunk, 'cod_municipio');

                // Delete existing rows in this chunk (for idempotency)
                DB::table('ciudades')->whereIn('cod_municipio', $codes)->delete();

                // Insert all at once
                DB::table('ciudades')->insert($chunk);
            }
        });

        $this->command->info("Ciudades seeded: " . count($rows) . " rows ({$skipped} skipped).");
    }
}
