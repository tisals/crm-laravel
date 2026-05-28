<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Import real detalle data from detalle_oportunidad.csv.
 *
 * Replaces any synthetic detalles (built from oportunidad.valor_sin_iva)
 * with the real line-item data from the dedicated CSV.
 *
 * Run: php artisan db:seed --class=DetalleOportunidadCsvSeeder
 */
class DetalleOportunidadCsvSeeder extends Seeder
{
    use CsvSeederTrait;

    private const CHUNK_SIZE = 200;

    public function run(): void
    {
        $csvFile = $this->csvPath('detalle_oportunidad.csv');

        if (!file_exists($csvFile)) {
            $this->command->error("CSV not found: {$csvFile}");
            return;
        }

        $this->command->info('Loading oportunidades by codigo...');
        $oppsByCodigo = DB::table('oportunidad')
            ->pluck('id', 'codigo')
            ->mapWithKeys(fn ($id, $cod) => [trim($cod) => $id])
            ->toArray();
        $this->command->info("  → " . count($oppsByCodigo) . " oportunidades loaded.");

        // Parse CSV and collect rows grouped by codigo
        $this->command->info('Parsing detalle CSV...');
        $detalleGroups = []; // codigo => [rows]
        $totalRows = 0;
        $unknownCodigos = [];

        foreach ($this->parseCsv($csvFile) as $row) {
            $rawCodigo = $row['no_cotizacion'] ?? '';
            $codigo = trim(str_replace("\xEF\xBB\xBF", '', $rawCodigo));

            if (empty($codigo)) continue;
            $totalRows++;

            $oppId = $oppsByCodigo[$codigo] ?? null;
            if (!$oppId) {
                $unknownCodigos[$codigo] = true;
                continue;
            }

            $concepto = $this->cleanStr($row['concepto'] ?? null);
            if ($concepto && strlen($concepto) > 65000) {
                $concepto = mb_substr($concepto, 0, 65000);
            }

            $medida = $this->cleanStr($row['medida'] ?? null);
            if ($medida && strlen($medida) > 20) {
                $medida = mb_substr($medida, 0, 20);
            }

            $detalleGroups[$codigo][] = [
                'oportunidad_id' => $oppId,
                'cod'            => $row['cod'] ?? '01',
                'producto'       => $this->cleanStr($row['producto'] ?? null),
                'concepto'       => $concepto,
                'medida'         => $medida ?? 'Unidad',
                'cantidad'       => $this->parseCantidad($row['cantidad'] ?? null),
                'iva_pct'        => $this->parseIva($row['iva'] ?? null),
                'vr_unitario'    => $this->parseMonetary($row['vrunitario'] ?? null),
                'vr_total'       => $this->parseMonetary($row['vr_total'] ?? null),
            ];
        }

        $this->command->info("  → {$totalRows} rows parsed.");
        $this->command->info("  → " . count($detalleGroups) . " unique codigos matched.");

        if (!empty($unknownCodigos)) {
            $this->command->warn("  → " . count($unknownCodigos) . " codigos NOT found in DB (skipped).");
        }

        // Delete ALL existing synthetic detalles and re-insert from CSV
        $this->command->info('Replacing existing detalles...');
        $existingCount = DB::table('detalle_oportunidad')->count();
        $this->command->info("  → {$existingCount} existing detalles to replace.");

        DB::transaction(function () use ($detalleGroups) {
            // Gather all oportunidad_ids that will receive new detalles
            $oppIds = array_unique(
                array_merge(...array_map(fn ($g) => array_column($g, 'oportunidad_id'), array_values($detalleGroups)))
            );

            // Batch delete old detalles for these ops
            foreach (array_chunk($oppIds, self::CHUNK_SIZE) as $chunk) {
                DB::table('detalle_oportunidad')
                    ->whereIn('oportunidad_id', $chunk)
                    ->delete();
            }

            // Batch insert new detalles
            $now = now();
            $insertBatch = [];
            $inserted = 0;

            foreach ($detalleGroups as $codigo => $rows) {
                foreach ($rows as $r) {
                    $insertBatch[] = [
                        'oportunidad_id' => $r['oportunidad_id'],
                        'producto_id'    => 1, // fallback; will be matched by name in future
                        'concepto'       => $r['concepto'],
                        'descripcion'    => $r['producto'] ?? $r['concepto'] ?? '',
                        'medida'         => $r['medida'],
                        'cantidad'       => $r['cantidad'],
                        'vr_unitario'    => $r['vr_unitario'],
                        'iva'            => $r['vr_unitario'] * ($r['iva_pct'] / 100),
                        'vr_total'       => $r['vr_total'],
                        'created_by'     => 1,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];

                    if (count($insertBatch) >= self::CHUNK_SIZE) {
                        DB::table('detalle_oportunidad')->insert($insertBatch);
                        $inserted += count($insertBatch);
                        $insertBatch = [];
                    }
                }
            }

            if (!empty($insertBatch)) {
                DB::table('detalle_oportunidad')->insert($insertBatch);
                $inserted += count($insertBatch);
            }

            $this->command->info("  → {$inserted} detalles inserted.");
        });

        // Report
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📊 DETALLE IMPORT RESULT');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $totalDetalles = DB::table('detalle_oportunidad')->count();
        $totalOpps = DB::table('oportunidad')->count();
        $conDetalle = DB::table('oportunidad')
            ->join('detalle_oportunidad', 'oportunidad.id', '=', 'detalle_oportunidad.oportunidad_id')
            ->distinct('oportunidad.id')
            ->count('oportunidad.id');
        $this->command->info("  Oportunidades total     : {$totalOpps}");
        $this->command->info("  Detalles insertados     : {$totalDetalles}");
        $this->command->info("  Ops con detalle         : {$conDetalle}");
        $this->command->info("  Ops sin detalle         : " . ($totalOpps - $conDetalle));
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    // --- Parsing helpers ---

    private function parseCantidad(?string $value): int
    {
        if (!$value) return 1;
        $value = trim($value);
        // Remove $ and spaces
        $value = str_replace(['$', ' ', ','], '', $value);
        return max(1, (int) $value);
    }

    private function parseIva(?string $value): float
    {
        if (!$value) return 0.0;
        return (float) str_replace(['%', ' '], '', trim($value));
    }

    private function parseMonetary(?string $value): float
    {
        if (!$value) return 0.0;
        $value = trim($value);
        // Remove currency symbol and leading/trailing space
        $value = str_replace('$', '', $value);
        $value = trim($value);

        if (empty($value)) return 0.0;

        // Colombian format: "." = thousands, "," = decimal
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace('.', '', $value);
        }

        return (float) $value;
    }

    private function cleanStr(?string $value, bool $truncateLong = true): ?string
    {
        if ($value === null || $value === '') return null;
        $cleaned = trim($value);
        if ($cleaned === '') return null;
        // Clean up excessive whitespace/newlines
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        // Truncate very long strings for DB columns
        if ($truncateLong && strlen($cleaned) > 500) {
            $cleaned = mb_substr($cleaned, 0, 500) . '...';
        }
        return $cleaned;
    }
}
