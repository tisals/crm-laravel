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
        if (!$value) {
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
        $entidades = DB::table('entidad')->get(['id', 'nombre', 'identificacion', 'dominio', 'tipo_id']);
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

            // Domain lookup
            if ($ent->dominio) {
                $domain = explode('.', $ent->dominio)[0];
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
            $name = preg_replace('/' . preg_quote($suffix, '/') . '$/', '', $name);
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
            if (strlen($part) >= 3 && !in_array($part, $stopWords)) {
                $words[$part] = $part;
            }
        }
        return array_values($words);
    }

    protected function findEntidadId(?string $value, array $entidadMap): ?int
    {
        if (!$value) {
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

        if (!file_exists($csvFile)) {
            $this->command->error("CSV file not found: {$csvFile}");
            return;
        }

        $entidadMap = $this->buildEntidadMap();
        $seen = [];
        $rows = [];
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

            // Look up entidad_id — allow null if not found (entidad_id is nullable)
            $entidadRef = $row['entidad'] ?? null;
            $entidadId = $entidadRef ? $this->findEntidadId($entidadRef, $entidadMap) : null;

            if (!$entidadId && !empty($entidadRef) && $entidadRef !== '#N/D' && $entidadRef !== '0') {
                // Had a reference but couldn't match — still create contact with null entidad_id
                $withNullEntidad++;
            }

            if (!$entidadId) {
                $withNullEntidad++;
            }

            // Deduplicate by (entidad_id, email_contacto) — null entidad_id is OK
            $dedupKey = ($entidadId ?? 'null') . ":{$email}";
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

            $rows[] = [
                'entidad_id' => $entidadId,
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
        }

        if (empty($rows)) {
            $this->command->warn("No valid rows found in CSV.");
            return;
        }

        DB::transaction(function () use ($rows) {
            // Delete existing contacts for matched entities OR all contacts with null entidad_id
            $entidadIds = array_unique(array_column($rows, 'entidad_id'));
            $nonNullIds = array_filter($entidadIds, fn($id) => $id !== null);
            if (!empty($nonNullIds)) {
                DB::table('contacto')->whereIn('entidad_id', $nonNullIds)->delete();
            }
            // Delete contacts with null entidad_id
            DB::table('contacto')->whereNull('entidad_id')->delete();

            DB::table('contacto')->insert($rows);
        });

        $this->command->info("Contactos seeded: " . count($rows) . " rows ({$skippedNoEmail} no email, {$skippedNoEntidad} unmatched ref, {$skippedDuplicate} duplicates, {$withNullEntidad} without entidad).");
    }
}
