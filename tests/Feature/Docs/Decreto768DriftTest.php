<?php

namespace Tests\Feature\Docs;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR1 of `complementar-entidad` — work-unit 1.5.
 *
 * The Decreto 768/2022 risk matrix lives in two places:
 *   - Laravel:  config/decreto_768.php       (PHP array; `entries` key)
 *   - Python:   mcp_server/resources/decreto_768.json (JSON; `entries` key)
 *
 * This test mirrors the CI SHA256 drift check. We compare NORMALISED
 * content (the canonical `entries` map, JSON-encoded for byte-identity)
 * so unrelated formatting changes (comments, whitespace, key order in
 * the JSON `_meta` block) don't trigger drift.
 *
 * PR1 ships both files with empty `entries` → trivially matches.
 * PR6 will fill both with the canonical Decreto 768/2022 rows and the
 * normalised SHA256 must keep matching.
 */
class Decreto768DriftTest extends TestCase
{
    #[Test]
    public function php_matrix_file_exists(): void
    {
        $this->assertFileExists(
            base_path('config/decreto_768.php'),
            'Expected config/decreto_768.php to exist (PR1 scaffold).'
        );
    }

    #[Test]
    public function python_matrix_file_exists(): void
    {
        $this->assertFileExists(
            base_path('mcp_server/resources/decreto_768.json'),
            'Expected mcp_server/resources/decreto_768.json to exist (PR1 scaffold).'
        );
    }

    #[Test]
    public function both_matrix_files_have_matching_normalised_sha256(): void
    {
        // Normalise each file to its canonical `entries` map, JSON-encoded
        // with sorted keys, so SHA256 (and semantic content) match exactly.
        $phpEntries = (array) config('decreto_768.entries', []);
        $jsonEntries = json_decode(
            (string) file_get_contents(base_path('mcp_server/resources/decreto_768.json')),
            true
        )['entries'] ?? [];

        $phpCanonical = self::canonicalJsonEncode($phpEntries);
        $jsonCanonical = self::canonicalJsonEncode($jsonEntries);

        $this->assertSame(
            $phpCanonical,
            $jsonCanonical,
            "Decreto matrix drift detected.\n  PHP canonical:  {$phpCanonical}\n  JSON canonical: {$jsonCanonical}"
        );
    }

    /**
     * Deterministic JSON encode (sorted keys, no slashes escaped) so two
     * equal associative arrays always produce the same byte string.
     */
    private static function canonicalJsonEncode(array $value): string
    {
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonicalJsonEncode($v);
            }
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}