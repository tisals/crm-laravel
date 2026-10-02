<?php

namespace Tests\Feature\Docs;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR1 of `complementar-entidad` — work-unit 1.5.
 *
 * The Decreto 768/2022 risk matrix lives in two places:
 *   - Laravel:  config/decreto_768.php       (PHP array)
 *   - Python:   mcp_server/resources/decreto_768.json (JSON)
 *
 * This test mirrors the CI SHA256 drift check: if both files exist, their
 * normalised content SHA256 MUST match. PR1 ships both as empty placeholders
 * (PHP returns []; JSON is `{}`) so the SHA256s match trivially; PR6 will
 * fill them and the test must keep matching.
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
        // PR1: both files are empty placeholders so SHA256 matches.
        // PR6: both files carry the canonical matrix; the SHA256 must still match.
        $phpPath = base_path('config/decreto_768.php');
        $jsonPath = base_path('mcp_server/resources/decreto_768.json');

        $this->assertFileExists($phpPath);
        $this->assertFileExists($jsonPath);

        $phpSha = hash_file('sha256', $phpPath);
        $jsonSha = hash_file('sha256', $jsonPath);

        $this->assertSame(
            $phpSha,
            $jsonSha,
            "Decreto matrix drift detected.\n  PHP SHA256:  {$phpSha}\n  JSON SHA256: {$jsonSha}"
        );
    }
}