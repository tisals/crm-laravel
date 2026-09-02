<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `entidad.email` → `emails` (entidad_id side).
 *
 * Idempotent: skips entidades that already have an `emails` row.
 * Legacy `entidad.email` stays in place per Commit 3 contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO `emails`
                (`entidad_id`, `email`, `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT
                e.id,
                LOWER(TRIM(e.email)),
                'trabajo',
                1,
                NOW(),
                NOW()
            FROM `entidad` e
            WHERE e.email IS NOT NULL
              AND TRIM(e.email) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `emails` em
                  WHERE em.entidad_id = e.id
                    AND em.deleted_at IS NULL
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM `emails`
            WHERE `es_principal` = 1
              AND `tipo` = 'trabajo'
              AND `created_at` >= ?
        SQL, ['2026-09-02 13:00:00']);
    }
};
