<?php

namespace App\Empresas\Infrastructure\Decreto;

/**
 * PR1 of `complementar-entidad` — decreto_768-risk-matrix D1.
 *
 * Stateless lookup over the Decreto 768/2022 CIIU → ARL risk class +
 * economic sector matrix. The matrix lives in `config/decreto_768.php`
 * (Laravel) and is mirrored at `mcp_server/resources/decreto_768.json`
 * (Python); CI enforces identical SHA256 of the two.
 *
 * Contract:
 *   - Input: any CIIU string (4-digit or 7-digit, with optional spaces).
 *   - Normalisation: digits 2-5 of a 7-digit code, or the 4-digit code as-is.
 *   - On exact match: returns the matrix entry unchanged.
 *   - On miss: applies the inference rule by the FIRST digit of the 4-digit
 *     code (see `inferByFirstDigit()`).
 *   - When the matrix has NO entries (PR1 placeholder), returns null even
 *     for the inference branch — there is no data to fall back to.
 *
 * @see https://www.mintrabajo.gov.co/documents/20147/0/Decreto+768+del+16+de+mayo+de+2022.pdf
 */
class Decreto768Lookup
{
    /**
     * Resolve a Decreto 768/2022 risk entry for the given CIIU code.
     *
     * @return array{clase_riesgo_ul_num:int, clase_riesgo_ul_desc:string, sector_economico:string, fuente:string}|null
     */
    public static function lookup(string $ciiu): ?array
    {
        $normalized = self::normalizeToFourDigits($ciiu);

        if ($normalized === null) {
            return null;
        }

        $entries = (array) config('decreto_768.entries', []);

        if (array_key_exists($normalized, $entries)) {
            return $entries[$normalized];
        }

        // Inference fallback only kicks in once the matrix has data; an empty
        // matrix means PR1 (no Decreto rows seeded yet) → return null.
        if (empty($entries)) {
            return null;
        }

        return self::inferByFirstDigit($normalized);
    }

    /**
     * Normalise a CIIU string to its 4-digit core.
     *
     * Strips non-digits, then:
     *   - if exactly 7 digits, returns digits 2-5 (the "class" code),
     *   - if exactly 4 digits, returns it as-is,
     *   - otherwise returns null (unrecognised shape).
     */
    private static function normalizeToFourDigits(string $ciiu): ?string
    {
        $digits = preg_replace('/\D+/', '', $ciiu) ?? '';

        if (strlen($digits) === 4) {
            return $digits;
        }

        if (strlen($digits) === 7) {
            // CIIU 7-digit format: AABBBBB. Digits 2-5 (BBBB) carry the class.
            return substr($digits, 1, 4);
        }

        return null;
    }

    /**
     * Inference rule per Decreto 768/2022 §3.3 (paraphrased):
     *   - first digit 0|1 → Primario  / ARL class III (3)
     *   - first digit 2|3|4 → Secundario / ARL class IV (4)
     *   - else           → Terciario / ARL class I   (1)
     */
    private static function inferByFirstDigit(string $normalizedFourDigits): array
    {
        $first = $normalizedFourDigits[0] ?? '9';

        if (in_array($first, ['0', '1'], true)) {
            return [
                'clase_riesgo_ul_num' => 3,
                'clase_riesgo_ul_desc' => 'Primario',
                'sector_economico' => 'Primario',
                'fuente' => 'decreto_768/2022/inference',
            ];
        }

        if (in_array($first, ['2', '3', '4'], true)) {
            return [
                'clase_riesgo_ul_num' => 4,
                'clase_riesgo_ul_desc' => 'Secundario',
                'sector_economico' => 'Secundario',
                'fuente' => 'decreto_768/2022/inference',
            ];
        }

        return [
            'clase_riesgo_ul_num' => 1,
            'clase_riesgo_ul_desc' => 'Terciario',
            'sector_economico' => 'Terciario',
            'fuente' => 'decreto_768/2022/inference',
        ];
    }
}