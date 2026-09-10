<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactoCsvSeeder extends Seeder
{
    use CsvSeederTrait;

    /**
     * Map etapa from CSV numeric codes using maestros.csv (Etapa_contacto):
     * 24 → Prospecto, 25 → Cliente, 26 → Propia, 27 → Inactivo
     */
    protected function mapEtapa(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return match ($value) {
            '24' => 'Prospecto',
            '25' => 'Cliente',
            '26' => 'Propia',
            '27' => 'Inactivo',
            default => $this->cleanCell($value) !== '' ? $this->cleanCell($value) : null,
        };
    }

    /**
     * Detect if a value looks like a NIT (numeric with optional dots/dashes).
     */
    protected function looksLikeNit(string $value): bool
    {
        $clean = preg_replace('/[\.\-\s]/', '', $value);

        return ctype_digit($clean) && strlen($clean) >= 5;
    }

    protected function buildEntidadMap(): array
    {
        // Commit 4 dropped `entidad.dominio` and `entidad.red_social_url`.
        // The canonical replacement is `presencia_online.url` with
        // tipo='web' (was `dominio`) or tipo='red_social' (was
        // `red_social_url`). We load both tables in one round trip and
        // join by entidad_id in PHP below.
        $entidades = DB::table('entidad')->get(['id', 'nombre', 'identificacion', 'tipo_id']);
        // Index presencia_online by entidad_id for the domain lookup
        // below. We pick the principal row when present, otherwise the
        // most recent one — same precedence the legacy `dominio`
        // column carried implicitly.
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

        foreach ($entidades as $ent) {
            $id = $ent->id;

            // Direct ID lookup
            $map[(string) $id] = $id;

            // NIT / identificación lookup (primary cross-reference)
            if ($ent->identificacion) {
                $nit = trim($ent->identificacion);
                $map[$nit] = $id;
                // Also store without dots/dashes for flexible matching
                $nitClean = preg_replace('/[\.\-\s]/', '', $nit);
                if ($nitClean !== $nit) {
                    $map[$nitClean] = $id;
                }
            }

            // Domain lookup — comes from presencia_online now.
            $dominio = $dominioByEntidad[$id] ?? null;
            if ($dominio) {
                $domain = explode('.', $dominio)[0];
                $map[strtolower($domain)] = $id;
            }

            // Full name lookup
            $nameLower = strtolower(trim($ent->nombre));
            $map[$nameLower] = $id;

            // Normalized name (without legal suffixes)
            $normalized = $this->normalizeEntityName($ent->nombre);
            $map[$normalized] = $id;

            // Keywords for fuzzy matching
            $words = $this->extractKeyWords($ent->nombre);
            foreach ($words as $word) {
                $map[$word] = $id;
            }
        }

        return $map;
    }

    protected function normalizeEntityName(string $name): string
    {
        $name = strtolower(trim($name));
        $suffixes = [
            ' s.a.s', ' sas', ' s.a.', ' sa', ' ltda', ' ltd', ' s en c',
            ' sociedad anonima', ' sociedad por acciones simplificadas',
            ' s.a', ' cia', ' e.u.', ' eu', ' inc', ' corp', ' foundation',
            ' fundacion', ' corporacion', ' cooperativa',
        ];
        foreach ($suffixes as $suffix) {
            $name = preg_replace('/'.preg_quote($suffix, '/').'$/', '', $name);
        }
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    protected function extractKeyWords(string $name): array
    {
        $name = strtolower($name);
        $name = preg_replace('/\b(sas|s\.a\.s|s\.a\.|ltda|ltd|sa|en c|sociedad|anonima)\b/i', '', $name);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $name);
        $stopWords = ['y', 'de', 'del', 'la', 'los', 'las', 'el', 'en', 'para', 'con', 'sin', 'por'];
        $words = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (strlen($part) >= 3 && ! in_array($part, $stopWords)) {
                $words[$part] = $part;
            }
        }

        return array_values($words);
    }

    protected function findEntidadId(?string $value, array $entidadMap): ?int
    {
        if (! $value) {
            return null;
        }

        $value = trim($value);

        // Primary cross-reference: NIT / identificación (supports 900444852, 900.444.852-1, etc.)
        if ($this->looksLikeNit($value)) {
            $clean = preg_replace('/[\.\-\s]/', '', $value);
            if (isset($entidadMap[$clean])) {
                return $entidadMap[$clean];
            }
            if (isset($entidadMap[$value])) {
                return $entidadMap[$value];
            }
            // also try lower just in case
            $lowerNit = strtolower($value);
            if (isset($entidadMap[$lowerNit])) {
                return $entidadMap[$lowerNit];
            }
        }

        if (isset($entidadMap[$value])) {
            return $entidadMap[$value];
        }

        $lower = strtolower($value);
        if (isset($entidadMap[$lower])) {
            return $entidadMap[$lower];
        }

        $normalized = $this->normalizeEntityName($value);
        if (isset($entidadMap[$normalized])) {
            return $entidadMap[$normalized];
        }

        $csvWords = $this->extractKeyWords($value);
        if (empty($csvWords)) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0;

        $byId = [];
        foreach ($entidadMap as $key => $id) {
            $byId[$id][] = $key;
        }

        foreach ($byId as $id => $keys) {
            $score = 0;
            foreach ($csvWords as $csvWord) {
                foreach ($keys as $key) {
                    if (str_contains($key, $csvWord) || str_contains($csvWord, $key)) {
                        $score++;
                        break;
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $id;
            }
        }

        if ($bestScore >= 1) {
            return $bestMatch;
        }

        return null;
    }

    public function run(): void
    {
        $csvFile = $this->csvPath('contactos.csv');

        if (! file_exists($csvFile)) {
            $this->command->error("CSV file not found: {$csvFile}");

            return;
        }

        $entidadMap = $this->buildEntidadMap();
        $seen = [];
        $rows = [];
        $pivots = [];
        $skippedNoEmail = 0;
        $skippedNoEntidad = 0;
        $skippedDuplicate = 0;
        $withNullEntidad = 0;

        foreach ($this->parseCsv($csvFile) as $row) {
            $email = $row['email_contacto'] ?? null;

            if (empty($email)) {
                $skippedNoEmail++;

                continue;
            }

            $email = explode("\n", $email)[0];
            $email = trim($email);

            if (empty($email)) {
                $skippedNoEmail++;

                continue;
            }

            // Look up entidad_id — allow null if not found.
            // Per commit fe99f70: contacto.entidad_id was dropped; the
            // CSV importer stores it transiently for dedup purposes and
            // writes it as an entidad_persona pivot row at the end.
            $entidadRef = $row['entidad'] ?? null;
            $entidadId = $entidadRef ? $this->findEntidadId($entidadRef, $entidadMap) : null;

            if (! $entidadId && ! empty($entidadRef) && $entidadRef !== '#N/D' && $entidadRef !== '0') {
                // Had a reference but couldn't match — still create contact with null entidad_id
                $withNullEntidad++;
            }

            if (! $entidadId) {
                $withNullEntidad++;
            }

            // Deduplicate by (entidad_id, email_contacto) — null entidad_id is OK
            $dedupKey = ($entidadId ?? 'null').":{$email}";
            if (isset($seen[$dedupKey])) {
                $skippedDuplicate++;

                continue;
            }
            $seen[$dedupKey] = true;

            $emailSecundario = $row['email_2_contacto'] ?? null;
            if ($emailSecundario) {
                $emailSecundario = explode("\n", $emailSecundario)[0];
                $emailSecundario = trim($emailSecundario);
                if ($emailSecundario === '') {
                    $emailSecundario = null;
                }
            }

            // Resolve persona_id by email. Commit 8 dropped
            // `personas.email_principal` — the canonical lookup is now
            // the primary row in the `emails` table. For contacts
            // without a persona we create one inline so the
            // entidad_persona pivot can be written.
            //
            // Note: `personas.tipo_persona` is also dropped (Commit 1),
            // so we never insert that column — `Persona::getTipoPersona
            // Attribute()` reads it from the bound entidad when needed.
            $personaId = DB::table('emails')
                ->where('email', $email)
                ->where('es_principal', 1)
                ->value('persona_id');

            if (! $personaId) {
                $personaId = DB::table('personas')->insertGetId([
                    'nombres' => $row['nombres'] ?? '',
                    'apellidos' => $row['apellidos'] ?? '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                // Stamp the primary email row so future lookups succeed
                // (and so the canonical "primary email" lives on the
                // `emails` table, not on `personas`).
                DB::table('emails')->insert([
                    'persona_id' => $personaId,
                    'email' => $email,
                    'tipo' => 'personal',
                    'es_principal' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $rows[] = [
                'persona_id' => $personaId,
                'email_contacto' => $email,
                'nombres' => $row['nombres'] ?? '',
                'apellidos' => $row['apellidos'] ?? '',
                'cargo' => $row['cargo'] ?? null,
                'tel_contacto' => $row['tel_contacto'] ?? null,
                'movil' => $row['movil'] ?? null,
                'email_secundario' => $emailSecundario,
                'rol' => $row['rol'] ?? null,
                'etapa' => $this->mapEtapa($row['etapa'] ?? null),
                'estado' => 'Activo',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Defer pivot writes until after bulk insert (we'll need the
            // contacto id, which insertGetId would force to per-row).
            $pivots[] = ['persona_id' => $personaId, 'entidad_id' => $entidadId];
        }

        if (empty($rows)) {
            $this->command->warn('No valid rows found in CSV.');

            return;
        }

        DB::transaction(function () use ($rows, $pivots) {
            // Delete existing contactos whose persona_id overlaps with the
            // incoming rows (the legacy code matched on entidad_id; with
            // that column gone we match on the persona pivot instead).
            $personaIds = array_unique(array_filter(array_column($rows, 'persona_id')));
            if (! empty($personaIds)) {
                DB::table('contacto')
                    ->whereIn('persona_id', $personaIds)
                    ->delete();
                // Drop the corresponding pivots so the bulk insert below
                // is conflict-free.
                DB::table('entidad_persona')
                    ->whereIn('persona_id', $personaIds)
                    ->delete();
            }

            DB::table('contacto')->insert($rows);

            // Write the pivot rows. contacto.entidad_id was dropped (commit
            // fe99f70); the canonical link is now entidad_persona.
            $now = now();
            $pivotRows = [];
            foreach ($pivots as $p) {
                if (! $p['entidad_id']) {
                    continue;
                }
                $pivotRows[] = [
                    'persona_id' => $p['persona_id'],
                    'entidad_id' => $p['entidad_id'],
                    'categoria' => 'asignacion',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (! empty($pivotRows)) {
                DB::table('entidad_persona')->insert($pivotRows);
            }
        });

        $this->command->info('Contactos seeded: '.count($rows)." rows ({$skippedNoEmail} no email, {$skippedNoEntidad} unmatched ref, {$skippedDuplicate} duplicates, {$withNullEntidad} without entidad).");

        // NOTE: DOD cap is NOT applied here because the cap is enforced by
        // DodCapSeeder, which runs LAST in DatabaseSeeder. This avoids any
        // ordering issues with downstream seeders that depend on contacts
        // (e.g., OportunidadCsvSeeder resolves contacto_id by email).
    }

    /**
     * Apply the DOD cap on the `contacto` table. See OportunidadCsvSeeder::applyDodCap
     * for the same logic on opportunities.
     *
     * Per commit fe99f70: `contacto.entidad_id` was dropped. The cap is
     * now applied against contactos belonging to each entidad via the
     * `entidad_persona` pivot (keyed on the contacto's persona_id).
     *
     * Returns ['contactos_eliminados' => int].
     */
    public function applyDodCap(int $maxOps = 10, int $maxContactos = 10): array
    {
        $stats = ['oportunidades_eliminadas' => 0, 'contactos_eliminados' => 0];

        if ($maxContactos > 0) {
            $rowsToDelete = $this->countContactosOverCap($maxContactos);

            // Cap per "entidad" — the entidad is the contacto's pivot-bound
            // entidad (resolved from contacto.persona_id → entidad_persona).
            DB::statement('
                DELETE FROM contacto
                WHERE id IN (
                    SELECT id FROM (
                        SELECT c.id,
                               ROW_NUMBER() OVER (
                                   PARTITION BY (
                                       SELECT ep.entidad_id
                                       FROM entidad_persona ep
                                       WHERE ep.persona_id = c.persona_id
                                       ORDER BY ep.entidad_id
                                       LIMIT 1
                                   )
                                   ORDER BY c.created_at DESC
                               ) AS rn
                        FROM contacto c
                        WHERE c.persona_id IS NOT NULL
                    ) t
                    WHERE rn > ?
                )
            ', [$maxContactos]);

            $stats['contactos_eliminados'] = $rowsToDelete;
        }

        return $stats;
    }

    private function countContactosOverCap(int $cap): int
    {
        $rows = DB::select('
            SELECT entidad_id, COUNT(*) AS total
            FROM (
                SELECT (
                    SELECT ep.entidad_id
                    FROM entidad_persona ep
                    WHERE ep.persona_id = c.persona_id
                    ORDER BY ep.entidad_id
                    LIMIT 1
                ) AS entidad_id
                FROM contacto c
                WHERE c.persona_id IS NOT NULL
            ) t
            WHERE entidad_id IS NOT NULL
            GROUP BY entidad_id
            HAVING total > ?
        ', [$cap]);

        return array_sum(array_map(fn ($r) => (int) $r->total - $cap, $rows));
    }
}
