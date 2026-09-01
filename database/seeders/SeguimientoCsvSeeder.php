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
 * - linked to oportunidad by codigo, resolves persona_id + entidad_id from that oportunidad
 *   (via contacto.persona_id mapping per PR-E)
 * - estado defaults to "Completado"
 *
 * Run: php artisan db:seed --class=SeguimientoCsvSeeder
 *
 * Per commit fe99f70: `contacto.entidad_id` was dropped. The contacto's
 * entidad binding is now on `entidad_persona`. This seeder doesn't write
 * entity_persona rows (the entity binding lives on the contacto); it only
 * reads `oportunidad.entidad_id` which is still a column.
 */
class SeguimientoCsvSeeder extends Seeder
{
    use CsvSeederTrait;

    private const CHUNK_SIZE = 50;

    private static ?int $defaultUserId = null;

    /**
     * Cache of contacto_id -> persona_id resolved from the contacto
     * table after the PR-E additive migration. Used to translate the
     * oportunidad.contacto_id into seguimiento.persona_id (the
     * post-PR-G canonical axis).
     *
     * @var array<int, int|null>
     */
    private array $contactoToPersona = [];

    public function run(): void
    {
        $csvFile = $this->csvPath('oportunidades.csv');

        if (! file_exists($csvFile)) {
            $this->command->error("CSV not found: {$csvFile}");

            return;
        }

        // Resolve a default user id for autor_id / created_by / updated_by.
        // The previous hardcoded `1` assumed the first user had id=1, which
        // is NOT guaranteed when UsuariosTableSeeder runs with updateOrCreate
        // and AUTO_INCREMENT has already advanced. If no user exists, leave
        // the fields NULL (they are nullable in the migration).
        self::$defaultUserId = DB::table('usuarios')->min('id');
        if (self::$defaultUserId === null) {
            $this->command->warn('No users found in `usuarios` table. autor_id / created_by / updated_by will be NULL.');
        } else {
            $this->command->info('Using user id '.self::$defaultUserId.' as default autor/created_by/updated_by.');
        }

        // PR-H: build the contacto -> persona cache so each seguimiento
        // row is stamped with the post-PR-G canonical FK
        // (seguimiento.persona_id). The contacto table carries the
        // additive persona_id column from PR-E, and the backfill
        // command (PR-F) populated it for non-NULL emails.
        $this->command->info('Building contacto -> persona cache...');
        $this->contactoToPersona = DB::table('contacto')
            ->select('id', 'persona_id')
            ->get()
            ->mapWithKeys(fn ($c) => [(int) $c->id => $c->persona_id !== null ? (int) $c->persona_id : null])
            ->all();
        $this->command->info('  -> '.count($this->contactoToPersona).' contacto rows cached.');

        // Load oportunidades map: codigo -> {id, contacto_id, entidad_id, fecha}
        $this->command->info('Loading oportunidades...');
        $opps = DB::table('oportunidad')
            ->select('id', 'codigo', 'contacto_id', 'entidad_id', 'fecha')
            ->get()
            ->keyBy(fn ($o) => trim($o->codigo));
        $this->command->info('  -> '.$opps->count().' oportunidades loaded.');

        // Parse CSV and collect seguimiento records
        $this->command->info('Parsing CSV for seguimiento data...');
        $seguimientos = [];
        $skippedCodigos = [];
        $skippedNoPersona = 0;
        $totalCsvRows = 0;
        $totalSeguimiento1 = 0;
        $totalSeguimiento2 = 0;

        foreach ($this->parseCsv($csvFile) as $row) {
            $totalCsvRows++;

            $codigo = trim($row['codigo'] ?? '');
            if (empty($codigo)) {
                continue;
            }

            $opp = $opps->get($codigo);
            if (! $opp) {
                $skippedCodigos[$codigo] = true;

                continue;
            }

            // PR-H: resolve persona_id from the oportunidad's contacto_id
            // (via contacto.persona_id). If the contacto has no persona_id
            // (e.g., backfill was incomplete), the seguimiento row is
            // skipped to avoid orphaning a reference.
            $personaId = $this->resolvePersonaId((int) $opp->contacto_id);
            if ($personaId === null) {
                $skippedNoPersona++;

                continue;
            }

            $raw1 = $row['seguimiento'] ?? null;
            $raw2 = $row['seguimiento_2'] ?? null;

            if ($raw1) {
                $seguimientos[] = $this->buildSeguimiento($opp, $personaId, $raw1);
                $totalSeguimiento1++;
            }
            if ($raw2) {
                $seguimientos[] = $this->buildSeguimiento($opp, $personaId, $raw2);
                $totalSeguimiento2++;
            }
        }

        $this->command->info("  CSV rows scanned: {$totalCsvRows}");
        $this->command->info("  Seguimientos from col 1: {$totalSeguimiento1}");
        $this->command->info("  Seguimientos from col 2: {$totalSeguimiento2}");
        $this->command->info('  Skipped (no persona_id on contacto): '.$skippedNoPersona);
        $this->command->info('  Total to insert: '.count($seguimientos));

        if (! empty($skippedCodigos)) {
            $this->command->warn('  -> '.count($skippedCodigos).' codigos NOT found in oportunidades table (skipped).');
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
        $this->command->info("  -> {$existingCount} existing rows.");

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

            if (! empty($insertBatch)) {
                DB::table('seguimiento')->insert($insertBatch);
                $inserted += count($insertBatch);
            }

            $this->command->info("  -> {$inserted} seguimientos inserted.");
        });

        // Report
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📊 SEGUIMIENTO IMPORT RESULT');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $totalSegs = DB::table('seguimiento')->count();
        $totalOpps = DB::table('oportunidad')->count();
        $conSeg = DB::table('oportunidad')
            ->join('seguimiento', 'oportunidad.id', '=', 'seguimiento.oportunidad_id')
            ->distinct('oportunidad.id')
            ->count('oportunidad.id');
        $this->command->info("  Oportunidades total      : {$totalOpps}");
        $this->command->info("  Seguimientos total       : {$totalSegs}");
        $this->command->info("  Ops con seguimiento      : {$conSeg}");
        $this->command->info('  Ops sin seguimiento      : '.($totalOpps - $conSeg));
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /**
     * Resolve persona_id from a contacto_id via the cached map.
     * Returns null if the contacto has no persona_id (backfill incomplete).
     */
    private function resolvePersonaId(int $contactoId): ?int
    {
        return $this->contactoToPersona[$contactoId] ?? null;
    }

    /**
     * Build a seguimiento row array from the raw CSV seguimiento text.
     */
    private function buildSeguimiento(object $opp, int $personaId, string $raw): array
    {
        $fecha = $this->extractDate($raw, $opp->fecha);
        $userId = self::$defaultUserId; // resolved once in run(), may be null

        return [
            'oportunidad_id' => $opp->id,
            // PR-H: stamp the post-PR-G canonical FK
            // (seguimiento.persona_id, FK -> personas.id).
            'persona_id' => $personaId,
            'entidad_id' => $opp->entidad_id,
            'tipo' => 'Llamada',
            'fecha' => $fecha,
            'hora' => null,
            'fecha_fin' => null,
            'notas' => $raw,
            'autor_id' => $userId,
            'estado' => 'Completado',
            'created_by' => $userId,
            'updated_by' => $userId,
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
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = $m[3];

            if (strlen($year) === 2) {
                $year = '20'.$year;
            }

            $dateStr = "{$year}-{$month}-{$day}";
            if (strtotime($dateStr) !== false) {
                return $dateStr;
            }
        }

        // Fallback: opp fecha + 7 days
        return date('Y-m-d', strtotime($oppFecha.' +7 days'));
    }
}
