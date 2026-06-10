<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EntidadCsvSeeder extends Seeder
{
    use CsvSeederTrait;

    /**
     * Map estado from CSV numeric codes using maestros.csv reference:
     * 25 → Cliente (Etapa_contacto)
     * 26 → Propia (Etapa_contacto)
     * 24 → Prospecto (Etapa_contacto)
     * 27 → Inactivo (Etapa_contacto)
     * Also handles text values for backward compatibility.
     */
    protected function mapEstado(?string $value): string
    {
        if (! $value) {
            return 'Prospecto';
        }

        // Numeric codes from maestros.csv (Etapa_contacto)
        return match ($value) {
            '25' => 'Prospecto',
            '26' => 'Cliente',
            '27' => 'Propia',
            '28' => 'Inactivo',
            '1' => 'Activo',     // Estado
            '2' => 'Inactivo',   // Estado
            '3' => 'Pendiente',  // Estado
            default => $this->mapEstadoTexto($value),
        };
    }

    protected function mapEstadoTexto(string $value): string
    {
        $lower = strtolower(trim($value));

        return match ($lower) {
            'activo', 'cliente' => 'Activo',
            'inactivo' => 'Inactivo',
            'prospecto' => 'Prospecto',
            'propia' => 'Propia',
            default => 'Prospecto',
        };
    }

    /**
     * Map tipo_persona from CSV numeric codes using maestros.csv:
     * 17 → Natural (PN - Persona Natural)
     * 18 → Juridica (PJ - Persona Jurídica)
     */
    protected function mapTipoPersona(?string $value): string
    {
        return match ($value) {
            '17' => 'Natural',
            '18' => 'Juridica',
            default => $value === 'Juridica' ? 'Juridica' : 'Natural',
        };
    }

    /**
     * Map tipo_id from CSV using maestros.csv (tipo_identificacion):
     * 14 → NIT, 15 → CE, 16 → CC
     */
    protected function mapTipoId(?string $value): ?string
    {
        return match ($value) {
            '14' => 'NIT',
            '15' => 'CE',
            '16' => 'CC',
            default => $value ? strtoupper(trim($value)) : null,
        };
    }

    /**
     * Build a lookup map of city name → cod_municipio in one query.
     */
    protected function buildCityMap(): array
    {
        $cities = DB::table('ciudades')->get(['nombre', 'cod_municipio']);
        $map = [];
        foreach ($cities as $city) {
            $map[strtolower(trim($city->nombre))] = $city->cod_municipio;
        }

        return $map;
    }

    protected function findCiudadCod(?string $cityName, array $cityMap): ?string
    {
        if (! $cityName) {
            return null;
        }

        $normalized = strtolower(trim($cityName));
        $normalized = str_replace(['dc', 'd.c.', 'd.c', 'bogotá dc', 'bogotá d.c.'], 'bogotá', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Exact match
        if (isset($cityMap[$normalized])) {
            return $cityMap[$normalized];
        }

        // Partial match — iterate (fast enough for ~1100 cities)
        foreach ($cityMap as $name => $code) {
            if (str_contains($name, $normalized) || str_contains($normalized, $name)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Aggressive entity name normalization: removes ALL variations of legal suffixes
     * (SAS, S.A.S., S. A. S., s a s, LTDA, L T D A, SA, E.U., S. en C., Inc, Corp, etc.),
     * punctuation, stop words — returns only the identifying core of the name.
     */
    protected function normalizeEntityName(string $name): string
    {
        $name = strtolower(trim($name));

        // Normalize spaces around punctuation
        $name = preg_replace('/\s*\.\s*/', '.', $name);
        $name = preg_replace('/\s*-\s*/', '-', $name);

        // Remove all known legal suffixes (order: specific → generic)
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

        // Remove standalone suffix words
        $removeWords = [
            'sas', 'ltda', 'ltd', 'sa', 's.a', 's.a.s', 'e.u', 'eu',
            'inc', 'corp', 'foundation', 'fundacion', 'corporacion',
            'sociedad', 'anonima', 'cooperativa', 'asociacion',
        ];
        $words = explode(' ', $name);
        $words = array_filter($words, fn ($w) => ! in_array(trim($w), $removeWords));
        $name = implode(' ', $words);

        // Remove special chars and collapse spaces
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        // Remove Spanish stop words
        $stopWords = ['y', 'de', 'del', 'la', 'los', 'las', 'el', 'en', 'para', 'con', 'sin', 'por', 'e', 'o', 'a', 'su', 'un', 'una'];
        $words = explode(' ', trim($name));
        $words = array_filter($words, fn ($w) => ! in_array(trim($w), $stopWords));
        $name = implode(' ', $words);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    public function run(): void
    {
        $csvFile = $this->csvPath('Entidades.csv');

        if (! file_exists($csvFile)) {
            $this->command->error("CSV file not found: {$csvFile}");

            return;
        }

        $jsonPath = database_path('csv/clientes_facturacion.json');
        $clientesFacturacion = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : ['nits' => [], 'names' => []];

        $cityMap = $this->buildCityMap();
        $rows = [];
        $skipped = 0;
        $cityLookups = 0;

        foreach ($this->parseCsv($csvFile) as $row) {
            $identificacion = $row['identificacion'] ?? null;

            if (empty($identificacion)) {
                $skipped++;

                continue;
            }

            $ciudadCod = null;
            $ciudadName = $row['ciudad'] ?? null;
            if ($ciudadName) {
                $ciudadCod = $this->findCiudadCod($ciudadName, $cityMap);
                if ($ciudadCod) {
                    $cityLookups++;
                }
            }

            // Cross-reference to determine if it is Cliente or Prospecto
            $isClient = false;
            $cleanNit = preg_replace('/[\.\-\s]/', '', $identificacion);
            if ($cleanNit) {
                if (in_array($cleanNit, $clientesFacturacion['nits'] ?? [])) {
                    $isClient = true;
                }
            }
            if (! $isClient) {
                $normalizedName = $this->normalizeEntityName($row['nombre'] ?? '');
                if (in_array($normalizedName, $clientesFacturacion['names'] ?? [])) {
                    $isClient = true;
                }
            }

            $estadoCsv = $this->mapEstado($row['estado'] ?? null);
            $estado = $isClient ? 'Cliente' : $estadoCsv;
            $fechaCreacion = $this->parseExcelDate($row['fecha_creacion'] ?? null) ?? now();
            $clienteDesde = $isClient ? $fechaCreacion : null;

            $rows[] = [
                'identificacion' => $identificacion,
                'tipo_persona' => $this->mapTipoPersona($row['tipo_persona'] ?? null),
                'tipo_id' => $this->mapTipoId($row['tipo_id'] ?? null),
                'nombre' => $row['nombre'] ?? 'Sin nombre',
                'nombre_comercial' => $row['nombre_comercial'] ?? null,
                'direccion' => $row['direccion'] ?? null,
                'ciudad_cod' => $ciudadCod,
                'dominio' => $row['dominio'] ?? null,
                'logo' => $row['logo'] ?? null,
                'estado' => $estado,
                'cliente_desde' => $clienteDesde,
                'created_at' => $fechaCreacion,
                'updated_at' => $this->parseExcelDate($row['fecha_actualizacion'] ?? null) ?? now(),
            ];
        }

        if (empty($rows)) {
            $this->command->warn('No valid rows found in CSV.');

            return;
        }

        DB::transaction(function () use ($rows) {
            $identificaciones = array_column($rows, 'identificacion');

            // Delete existing entities matching CSV data (by identification), but PRESERVE "Propia" brand entities
            DB::table('entidad')
                ->where('estado', '!=', 'Propia')
                ->whereIn('identificacion', $identificaciones)
                ->delete();

            // Skip CSV rows that would conflict with existing Propia entities (by NIT)
            $propiaNits = DB::table('entidad')
                ->where('estado', 'Propia')
                ->pluck('identificacion')
                ->all();

            $rows = array_values(array_filter($rows, fn ($r) =>
                ! in_array($r['identificacion'], $propiaNits)
            ));

            DB::table('entidad')->insert($rows);
        });

        $this->command->info('Entidades seeded: '.count($rows)." rows ({$skipped} skipped, {$cityLookups} city lookups).");
    }
}
