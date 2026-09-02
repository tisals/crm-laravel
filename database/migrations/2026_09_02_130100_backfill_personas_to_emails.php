<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `personas.email_principal` → `emails` (persona_id side).
 *
 * Idempotent: skips personas that already have an `emails` row.
 * Legacy `personas.email_principal` stays in place per Commit 3
 * "keep deprecated" contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO `emails`
                (`persona_id`, `email`, `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT
                p.id,
                LOWER(TRIM(p.email_principal)),
                'personal',
                1,
                NOW(),
                NOW()
            FROM `personas` p
            WHERE p.email_principal IS NOT NULL
              AND TRIM(p.email_principal) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `emails` e
                  WHERE e.persona_id = p.id
                    AND e.deleted_at IS NULL
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM `emails`
            WHERE `es_principal` = 1
              AND `tipo` = 'personal'
              AND `created_at` >= ?
        SQL, ['2026-09-02 13:00:00']);
    }
};
