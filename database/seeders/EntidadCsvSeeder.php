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
        if (!$value) {
            return 'Prospecto';
        }

        // Numeric codes from maestros.csv (Etapa_contacto)
        return match ($value) {
            '25' => 'Cliente',
            '26' => 'Propia',
            '24' => 'Prospecto',
            '27' => 'Inactivo',
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
        if (!$cityName) {
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

    public function run(): void
    {
        $csvFile = $this->csvPath('Entidades.csv');

        if (!file_exists($csvFile)) {
            $this->command->error("CSV file not found: {$csvFile}");
            return;
        }

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
                'estado' => $this->mapEstado($row['estado'] ?? null),
                'created_at' => $this->parseExcelDate($row['fecha_creacion'] ?? null) ?? now(),
                'updated_at' => $this->parseExcelDate($row['fecha_actualizacion'] ?? null) ?? now(),
            ];
        }

        if (empty($rows)) {
            $this->command->warn("No valid rows found in CSV.");
            return;
        }

        DB::transaction(function () use ($rows) {
            $identificaciones = array_column($rows, 'identificacion');
            DB::table('entidad')->whereIn('identificacion', $identificaciones)->delete();
            DB::table('entidad')->insert($rows);
        });

        $this->command->info("Entidades seeded: " . count($rows) . " rows ({$skipped} skipped, {$cityLookups} city lookups).");
    }
}
