<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaestroSeeder extends Seeder
{
    use CsvSeederTrait;

    public function run(): void
    {
        $csvFile = $this->csvPath('maestros.csv');

        if (!file_exists($csvFile)) {
            $this->command->warn("Maestros CSV not found: {$csvFile}. Skipping.");
            return;
        }

        $rows = [];

        foreach ($this->parseCsv($csvFile) as $row) {
            $id = $row['id'] ?? null;
            $nombre = $this->cleanCell($row['nombre'] ?? '');
            $campo = $this->cleanCell($row['campo'] ?? '');

            if (empty($id) || empty($nombre) || empty($campo)) {
                continue;
            }

            $rows[] = [
                'id' => (int) $id,
                'nombre' => $nombre,
                'campo' => $campo,
                'habilitado' => $this->cleanCell($row['habilitado'] ?? 'Y'),
                'created_at' => $this->parseExcelDate($row['created_at'] ?? null) ?? now(),
                'updated_at' => $this->parseExcelDate($row['updated_at'] ?? null) ?? now(),
            ];
        }

        if (empty($rows)) {
            $this->command->warn("No valid maestros rows found.");
            return;
        }

        // Truncate fuera de la transacción (MySQL: TRUNCATE es DDL, commitea implícitamente)
        DB::table('maestros')->truncate();
        DB::table('maestros')->insert($rows);

        // Group by campo for summary
        $byCampo = [];
        foreach ($rows as $r) {
            $byCampo[$r['campo']][] = $r['nombre'];
        }

        foreach ($byCampo as $campo => $nombres) {
            $this->command->info("  {$campo}: " . implode(', ', $nombres));
        }

        $this->command->info("Maestros seeded: " . count($rows) . " rows in " . count($byCampo) . " groups.");
    }
}
