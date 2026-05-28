<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Import follow-up (seguimiento) data from oportunidades.csv columns
 * "SEGUIMIENTO" and "Seguimiento 2".
 *
 * Rules:
 * - tipo defaults to "Llamada"
 * - fecha extracted from seguimiento text (first line); fallback: oportunidad.fecha + 7 days
 * - linked to oportunidad by codigo, resolves contacto_id + entidad_id from that oportunidad
 * - estado defaults to "Completado"
 *
 * Run: php artisan db:seed --class=SeguimientoCsvSeeder
 */
class SeguimientoCsvSeeder extends Seeder
{
    use CsvSeederTrait;

    private const CHUNK_SIZE = 50;

    public function run(): void
    {
        $csvFile = $this->csvPath('oportunidades.csv');

        if (!file_exists($csvFile)) {
            $this->command->error("CSV not found: {$csvFile}");
            return;
        }

        // Load oportunidades map: codigo → {id, contacto_id, entidad_id, fecha}
        $this->command->info('Loading oportunidades...');
        $opps = DB::table('oportunidad')
            ->select('id', 'codigo', 'contacto_id', 'entidad_id', 'fecha')
            ->get()
            ->keyBy(fn ($o) => trim($o->codigo));
        $this->command->info('  → ' . $opps->count() . ' oportunidades loaded.');

        // Parse CSV and collect seguimiento records
        $this->command->info('Parsing CSV for seguimiento data...');
        $seguimientos = [];
        $skippedCodigos = [];
        $totalCsvRows = 0;
        $totalSeguimiento1 = 0;
        $totalSeguimiento2 = 0;

        foreach ($this->parseCsv($csvFile) as $row) {
            $totalCsvRows++;

            $codigo = trim($row['codigo'] ?? '');
            if (empty($codigo)) continue;

            $opp = $opps->get($codigo);
            if (!$opp) {
                $skippedCodigos[$codigo] = true;
                continue;
            }

            $raw1 = $row['seguimiento'] ?? null;
            $raw2 = $row['seguimiento_2'] ?? null;

            if ($raw1) {
                $seguimientos[] = $this->buildSeguimiento($opp, $raw1, false);
                $totalSeguimiento1++;
            }
            if ($raw2) {
                $seguimientos[] = $this->buildSeguimiento($opp, $raw2, true);
                $totalSeguimiento2++;
            }
        }

        $this->command->info("  CSV rows scanned: {$totalCsvRows}");
        $this->command->info("  Seguimientos from col 1: {$totalSeguimiento1}");
        $this->command->info("  Seguimientos from col 2: {$totalSeguimiento2}");
        $this->command->info('  Total to insert: ' . count($seguimientos));

        if (!empty($skippedCodigos)) {
            $this->command->warn('  → ' . count($skippedCodigos) . ' codigos NOT found in oportunidades table (skipped).');
        }

        if (empty($seguimientos)) {
            $this->command->warn('No seguimiento data found in CSV.');
            return;
        }

        // Delete existing seguimientos tied to these oportunidades (clean re-import)
        $oppIds = array_unique(array_column($seguimientos, 'oportunidad_id'));
        $existingCount = DB::table('seguimiento')
            ->whereIn('oportunidad_id', $oppIds)
            ->count();
        $this->command->info('Deleting existing seguimientos for affected ops...');
        $this->command->info("  → {$existingCount} existing rows.");

        DB::transaction(function () use ($oppIds, $seguimientos) {
            // Batch delete
            foreach (array_chunk($oppIds, self::CHUNK_SIZE) as $chunk) {
                DB::table('seguimiento')
                    ->whereIn('oportunidad_id', $chunk)
                    ->delete();
            }

            // Batch insert
            $now = now();
            $insertBatch = [];
            $inserted = 0;

            foreach ($seguimientos as $s) {
                $insertBatch[] = array_merge($s, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if (count($insertBatch) >= self::CHUNK_SIZE) {
                    DB::table('seguimiento')->insert($insertBatch);
                    $inserted += count($insertBatch);
                    $insertBatch = [];
                }
            }

            if (!empty($insertBatch)) {
                DB::table('seguimiento')->insert($insertBatch);
                $inserted += count($insertBatch);
            }

            $this->command->info("  → {$inserted} seguimientos inserted.");
        });

        // Report
        $this->command->info('');
        $this->command->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->command->info("📊 SEGUIMIENTO IMPORT RESULT");
        $this->command->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $totalSegs = DB::table('seguimiento')->count();
        $totalOpps = DB::table('oportunidad')->count();
        $conSeg = DB::table('oportunidad')
            ->join('seguimiento', 'oportunidad.id', '=', 'seguimiento.oportunidad_id')
            ->distinct('oportunidad.id')
            ->count('oportunidad.id');
        $this->command->info("  Oportunidades total      : {$totalOpps}");
        $this->command->info("  Seguimientos total       : {$totalSegs}");
        $this->command->info("  Ops con seguimiento      : {$conSeg}");
        $this->command->info("  Ops sin seguimiento      : " . ($totalOpps - $conSeg));
        $this->command->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    /**
     * Build a seguimiento row array from the raw CSV seguimiento text.
     */
    private function buildSeguimiento(object $opp, string $raw, bool $isSecond): array
    {
        $fecha = $this->extractDate($raw, $opp->fecha);

        return [
            'oportunidad_id' => $opp->id,
            'contacto_id'    => $opp->contacto_id,
            'entidad_id'     => $opp->entidad_id,
            'tipo'           => 'Llamada',
            'fecha'          => $fecha,
            'hora'           => null,
            'fecha_fin'      => null,
            'notas'          => $raw,
            'autor_id'       => 1,
            'estado'         => 'Completado',
            'created_by'     => 1,
            'updated_by'     => 1,
        ];
    }

    /**
     * Extract a date from the first line of the seguimiento text.
     *
     * Supported formats:
     *   dd/mm/yyyy, dd-mm-yyyy, d/m/yy, dd/mm/yy, etc.
     *
     * If no date found, fallback to oportunidad.fecha + 7 days.
     */
    private function extractDate(string $text, string $oppFecha): string
    {
        $firstLine = explode("\n", $text)[0];
        // Normalize separators to slash for pattern matching
        $normalized = str_replace(['-', '.'], '/', $firstLine);

        // Match dd/mm/yyyy or d/m/yy or dd/m/yyyy etc.
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $normalized, $m)) {
            $day   = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year  = $m[3];

            if (strlen($year) === 2) {
                $year = '20' . $year;
            }

            $dateStr = "{$year}-{$month}-{$day}";
            if (strtotime($dateStr) !== false) {
                return $dateStr;
            }
        }

        // Fallback: opp fecha + 7 days
        return date('Y-m-d', strtotime($oppFecha . ' +7 days'));
    }
}
