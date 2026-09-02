<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `entidad.telefono` → `telefonos` (entidad_id side).
 *
 * Idempotent: skips entidades that already have a `telefonos` row.
 * Legacy `entidad.telefono` stays in place per Commit 3 contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO `telefonos`
                (`entidad_id`, `numero`, `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT
                e.id,
                TRIM(e.telefono),
                'trabajo',
                1,
                NOW(),
                NOW()
            FROM `entidad` e
            WHERE e.telefono IS NOT NULL
              AND TRIM(e.telefono) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `telefonos` t
                  WHERE t.entidad_id = e.id
                    AND t.deleted_at IS NULL
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM `telefonos`
            WHERE `es_principal` = 1
              AND `tipo` = 'trabajo'
              AND `created_at` >= ?
        SQL, ['2026-09-02 13:00:00']);
    }
};
