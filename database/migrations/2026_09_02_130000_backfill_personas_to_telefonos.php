<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `personas.telefono_principal` → `telefonos` (persona_id side).
 *
 * Idempotent: each migration only inserts rows for personas that don't
 * yet have a `telefonos` row. Re-running on a partially-backfilled
 * database is safe.
 *
 * Legacy columns (`personas.telefono_principal`, etc.) stay in place
 * per Commit 3's "keep deprecated" contract (option 2). A later
 * commit drops them once production callers are confirmed migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO `telefonos`
                (`persona_id`, `numero`, `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT
                p.id,
                TRIM(p.telefono_principal),
                'movil',
                1,
                NOW(),
                NOW()
            FROM `personas` p
            WHERE p.telefono_principal IS NOT NULL
              AND TRIM(p.telefono_principal) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `telefonos` t
                  WHERE t.persona_id = p.id
                    AND t.deleted_at IS NULL
              )
        SQL);
    }

    public function down(): void
    {
        // Backfill is destructive only of the new table's contents, not
        // the legacy columns. Down removes the rows we inserted.
        DB::statement(<<<'SQL'
            DELETE FROM `telefonos`
            WHERE `es_principal` = 1
              AND `tipo` = 'movil'
              AND `created_at` >= ?
        SQL, ['2026-09-02 13:00:00']);
    }
};
