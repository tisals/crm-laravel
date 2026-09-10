<?php

namespace Database\Seeders;

use App\Application\UseCases\Oportunidad\OportunidadCsvImportUseCase;
use App\Models\Producto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OportunidadCsvSeeder extends Seeder
{
    use CsvSeederTrait;

    private const CHUNK_SIZE = 100;

    public function run(): void
    {
        $csvFile = $this->csvPath('oportunidades.csv');

        if (! file_exists($csvFile)) {
            $this->command->error("CSV file not found: {$csvFile}");
            $this->command->info('Copy Docs/oportunidad.csv to database/csv/oportunidades.csv and retry.');

            return;
        }

        // --- Build maps ---
        $this->command->info('Building entity map...');
        $entityMap = $this->buildEntityMap();
        $this->command->info("  → {$entityMap['count']} entities indexed.");

        $this->command->info('Loading products...');
        $products = Producto::all();
        $fallbackProduct = Producto::find(1);
        $this->command->info("  → {$products->count()} products loaded.");

        // Build contact dedup map
        $this->command->info('Building contact dedup map...');
        $contactDedup = $this->buildContactDedupMap();

        // --- Parse CSV ---
        $this->command->info('Parsing CSV file...');
        $allRows = iterator_to_array($this->parseCsv($csvFile));
        $totalRows = count($allRows);
        $this->command->info("  → {$totalRows} rows parsed.");

        if ($totalRows === 0) {
            $this->command->warn('No rows found in CSV.');

            return;
        }

        // --- Initialize UseCase ---
        // Load maestro estado map: maestro.id → maestro.nombre
        $this->command->info('Loading estado map from maestros...');
        $maestrosEstado = DB::table('maestros')
            ->where('campo', 'Estado oportunidad')
            ->pluck('nombre', 'id')
            ->toArray();
        $this->command->info('  → '.count($maestrosEstado).' estados loaded.');

        $jsonPath = database_path('csv/clientes_facturacion.json');
        $clientesFacturacion = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : ['nits' => [], 'names' => []];

        $useCase = new OportunidadCsvImportUseCase;
        $useCase
            ->setEntityMap($entityMap['map'])
            ->setContactDedup($contactDedup)
            ->setProductMap($products->all())
            ->setFallbackProduct($fallbackProduct)
            ->setDefaultUserId(1)
            ->setEstadoMap($maestrosEstado)
            ->setClientesFacturacion($clientesFacturacion);

        // --- Process in chunks ---
        $totalCreated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        $allErrors = [];
        $chunks = array_chunk($allRows, self::CHUNK_SIZE);
        $chunkCount = count($chunks);

        $this->command->info("Processing {$chunkCount} chunks of ".self::CHUNK_SIZE.' rows...');
        $this->command->info('');

        $progressBar = $this->command->getOutput()->createProgressBar($chunkCount);
        $progressBar->start();

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                $result = $useCase->import($chunk);
                $totalCreated += $result['created'];
                $totalSkipped += $result['skipped'];
                $totalErrors += $result['errors'];
                if (! empty($result['details'])) {
                    foreach ($result['details'] as $err) {
                        $allErrors[] = $err;
                    }
                }
            } catch (\Throwable $e) {
                $totalErrors += count($chunk);
                $allErrors[] = [
                    'chunk' => $chunkIndex,
                    'error' => $e->getMessage(),
                ];
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->command->info('');

        // --- Report ---
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📊 IMPORT RESULT');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info("  Total rows in CSV : {$totalRows}");
        $this->command->info("  Created/updated   : {$totalCreated}");
        $this->command->info("  Skipped           : {$totalSkipped}");
        $this->command->info("  Errors            : {$totalErrors}");

        if (! empty($allErrors)) {
            $this->command->info('');
            $this->command->warn('Errors:');
            foreach (array_slice($allErrors, 0, 10) as $err) {
                $cod = $err['codigo'] ?? 'N/A';
                $msg = $err['error'] ?? 'Unknown';
                $this->command->warn("  - [{$cod}] {$msg}");
            }
            if (count($allErrors) > 10) {
                $this->command->warn('  ... and '.(count($allErrors) - 10).' more errors.');
            }
        }

        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // NOTE: DOD cap is NOT applied here because DetalleOportunidadCsvSeeder
        // runs after this seeder and needs all opportunities to exist for FK
        // inserts. The cap is applied by DodCapSeeder, which runs LAST in
        // DatabaseSeeder.
    }

    /**
     * Apply the DOD cap on the `oportunidad` table: no entity may have more
     * than $maxOps opportunities. The oldest rows (by fecha ASC) are deleted,
     * preserving the most recent work.
     *
     * Returns ['oportunidades_eliminadas' => int].
     *
     * Works on MySQL 8+ / MariaDB 10.2+ / SQLite 3.25+ (window functions).
     */
    public function applyDodCap(int $maxOps = 10, int $maxContactos = 10): array
    {
        $stats = ['oportunidades_eliminadas' => 0, 'contactos_eliminados' => 0];

        if ($maxOps > 0) {
            $rowsToDelete = $this->countOportunidadesOverCap($maxOps);

            DB::statement('
                DELETE FROM oportunidad
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id,
                               ROW_NUMBER() OVER (PARTITION BY entidad_id ORDER BY fecha DESC) AS rn
                        FROM oportunidad
                        WHERE entidad_id IS NOT NULL
                    ) t
                    WHERE rn > ?
                )
            ', [$maxOps]);

            $stats['oportunidades_eliminadas'] = $rowsToDelete;
        }

        // contactos cap is applied by ContactoCsvSeeder, not here.
        return $stats;
    }

    private function countOportunidadesOverCap(int $cap): int
    {
        $rows = DB::select('
            SELECT entidad_id, COUNT(*) AS total
            FROM oportunidad
            WHERE entidad_id IS NOT NULL
            GROUP BY entidad_id
            HAVING total > ?
        ', [$cap]);

        return array_sum(array_map(fn ($r) => (int) $r->total - $cap, $rows));
    }

    /**
     * Build entity lookup map from existing DB records.
     * Mirrors ContactoCsvSeeder::buildEntidadMap logic.
     *
     * @return array{map: array, count: int}
     */
    private function buildEntityMap(): array
    {
        // Commit 4 dropped `entidad.dominio`; the canonical replacement
        // is `presencia_online.url` with tipo='web'. We index by
        // entidad_id and pick the principal (or most-recent) row.
        $entidades = DB::table('entidad')->get(['id', 'nombre', 'identificacion', 'tipo_id']);
        $presencias = DB::table('presencia_online')
            ->where('tipo', 'web')
            ->whereNull('deleted_at')
            ->orderByDesc('es_principal')
            ->orderByDesc('id')
            ->get(['entidad_id', 'url']);
        $dominioByEntidad = [];
        foreach ($presencias as $po) {
            if ($po->entidad_id && ! isset($dominioByEntidad[$po->entidad_id])) {
                $dominioByEntidad[$po->entidad_id] = $po->url;
            }
        }

        $map = [];
        $count = 0;

        foreach ($entidades as $ent) {
            $id = (int) $ent->id;
            $count++;

            // By ID
            $map[(string) $id] = $id;

            // By NIT / identificación
            if ($ent->identificacion) {
                $nit = trim($ent->identificacion);
                $map[$nit] = $id;
                $nitClean = preg_replace('/[\.\-\s]/', '', $nit);
                if ($nitClean !== $nit) {
                    $map[$nitClean] = $id;
                }
            }

            // By domain — from presencia_online.
            $dominio = $dominioByEntidad[$id] ?? null;
            if ($dominio) {
                $domain = explode('.', $dominio)[0];
                $map[strtolower($domain)] = $id;
            }

            // By name
            $nameLower = strtolower(trim($ent->nombre));
            $map[$nameLower] = $id;

            // By normalized name
            $normalized = $this->normalizeEntityName($ent->nombre);
            $map[$normalized] = $id;
        }

        return ['map' => $map, 'count' => $count];
    }

    /**
     * Build contact dedup map from existing contacts.
     *
     * Commit fe99f70 dropped `contacto.entidad_id`; the canonical
     * link now lives on the `entidad_persona` pivot keyed on
     * `persona_id`. We resolve contacto → persona → entidad_persona
     * to get the dedup key.
     *
     * @return array<string, true>
     */
    private function buildContactDedupMap(): array
    {
        // Resolve (persona_id, email_contacto) pairs from `contacto`,
        // then attach the pivot-resolved entidad_id. We use a single
        // correlated subquery against `entidad_persona` to avoid
        // loading the entire pivot table into PHP.
        $rows = DB::select('
            SELECT c.persona_id,
                   c.email_contacto,
                   (
                       SELECT ep.entidad_id
                       FROM entidad_persona ep
                       WHERE ep.persona_id = c.persona_id
                       ORDER BY ep.entidad_id
                       LIMIT 1
                   ) AS entidad_id
            FROM contacto c
            WHERE c.email_contacto IS NOT NULL
              AND c.persona_id IS NOT NULL
        ');

        $map = [];
        foreach ($rows as $c) {
            if ($c->entidad_id === null) {
                continue;
            }
            $key = $c->entidad_id.':'.$c->email_contacto;
            $map[$key] = true;
        }

        return $map;
    }

    /**
     * Aggressive entity name normalization: removes ALL variations of legal suffixes
     * (SAS, S.A.S., S. A. S., s a s, LTDA, L T D A, SA, E.U., S. en C., Inc, Corp, etc.),
     * punctuation, stop words — returns only the identifying core of the name.
     */
    private function normalizeEntityName(string $name): string
    {
        $name = strtolower(trim($name));

        $name = preg_replace('/\s*\.\s*/', '.', $name);
        $name = preg_replace('/\s*-\s*/', '-', $name);

        $suffixPatterns = [
            '/^s\.?\s*a\.?\s*s\.?\s*\b/',
            '/\b(s\.?\s*a\.?\s*s\.?)\s*$/',
            '/\b(s\s*a\s*s)\s*$/',
            '/\b(l\.?\s*t\.?\s*d\.?\s*a\.?)\s*$/',
            '/\b(l\s*t\s*d\s*a?)\s*$/',
            '/\b(s\.?\s*a\.?)\s*$/',
            '/\b(s\s*a)\s*$/',
            '/\b(e\.?\s*u\.?)\s*$/',
            '/\b(s\.?\s*e\.?\s*n\.?\s*c\.?)\s*$/',
            '/\b(inc\.?)\s*$/',
            '/\b(corp\.?)\s*$/',
            '/\b(ltda\.?)\s*$/',
            '/\b(sas)\s*$/',
            '/\b(eu)\s*$/',
            '/\b(s\.a)\s*$/',
        ];
        foreach ($suffixPatterns as $pattern) {
            $name = preg_replace($pattern, '', $name);
        }

        $removeWords = [
            'sas', 'ltda', 'ltd', 'sa', 's.a', 's.a.s', 'e.u', 'eu',
            'inc', 'corp', 'foundation', 'fundacion', 'corporacion',
            'sociedad', 'anonima', 'cooperativa', 'asociacion',
        ];
        $words = explode(' ', $name);
        $words = array_filter($words, fn ($w) => ! in_array(trim($w), $removeWords));
        $name = implode(' ', $words);

        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        $stopWords = ['y', 'de', 'del', 'la', 'los', 'las', 'el', 'en', 'para', 'con', 'sin', 'por', 'e', 'o', 'a', 'su', 'un', 'una'];
        $words = explode(' ', trim($name));
        $words = array_filter($words, fn ($w) => ! in_array(trim($w), $stopWords));
        $name = implode(' ', $words);

        return trim(preg_replace('/\s+/', ' ', $name));
    }
}
