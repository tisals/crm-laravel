<?php

namespace Database\Seeders;

use RuntimeException;

/**
 * Shared CSV parsing logic for seeders that import from semicolon-delimited CSV files.
 *
 * Handles: Excel artifacts (#¿NOMBRE?), empty rows, date format conversion, percentage parsing.
 * Memory-efficient: processes row-by-row via fgetcsv, never loads entire file.
 */
trait CsvSeederTrait
{
    /**
     * Parse a semicolon-delimited CSV file row by row.
     *
     * @param string $path Absolute path to the CSV file
     * @param string $delimiter Column delimiter (default: semicolon)
     * @return \Generator Yields associative arrays [header => cleaned_value]
     */
    protected function parseCsv(string $path, string $delimiter = ';'): \Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        // Read and clean header row
        $rawHeaders = fgetcsv($handle, 0, $delimiter);
        if ($rawHeaders === false) {
            fclose($handle);
            throw new RuntimeException("CSV file is empty: {$path}");
        }

        $headers = $this->cleanHeaders($rawHeaders);

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            // Skip completely empty rows
            if ($this->isEmptyRow($data)) {
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $value = $data[$i] ?? null;
                $row[$header] = $this->cleanCell($value);
            }
            yield $row;
        }

        fclose($handle);
    }

    /**
     * Normalize header names: lowercase, trim, replace spaces/dashes with underscores.
     */
    protected function cleanHeaders(array $headers): array
    {
        return array_map(function ($h) {
            $h = trim(strtolower($h ?? ''));
            $h = str_replace([' ', '-'], '_', $h);
            // Remove any remaining non-alphanumeric chars except underscores
            $h = preg_replace('/[^a-z0-9_]/', '', $h);
            return $h;
        }, $headers);
    }

    /**
     * Clean a single cell value.
     *
     * - Strips Excel artifacts like #¿NOMBRE?
     * - Trims whitespace
     * - Returns null for empty strings
     */
    protected function cleanCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        // Excel artifact: #¿NOMBRE? → null
        if (str_contains($value, '#¿NOMBRE?') || str_contains($value, '#NAME?')) {
            return null;
        }

        // Empty string → null
        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Check if a row is completely empty (all cells are empty or whitespace only).
     */
    protected function isEmptyRow(array $data): bool
    {
        return empty(array_filter($data, fn ($v) => trim($v ?? '') !== ''));
    }

    /**
     * Convert Excel date format (dd/mm/YYYY or dd/mm/YYYY HH:MM:SS) to MySQL format.
     *
     * @return string|null Y-m-d or Y-m-d H:i:s, or null if unparseable
     */
    protected function parseExcelDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        // dd/mm/YYYY HH:MM:SS
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})/', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$m[6]}";
        }

        // dd/mm/YYYY
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }

    /**
     * Parse a percentage string like "19%" into a float (19.0).
     */
    protected function parsePercentage(?string $value): float
    {
        if (!$value) {
            return 0.0;
        }
        return (float) str_replace('%', '', trim($value));
    }

    /**
     * Pad a numeric code with leading zeros to a given length.
     * E.g., "5001" with length 5 → "05001"
     */
    protected function padCode(string $code, int $length = 5): string
    {
        return str_pad(trim($code), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Get the absolute path to a CSV file in the database/csv directory.
     */
    protected function csvPath(string $filename): string
    {
        return database_path("csv/{$filename}");
    }
}
