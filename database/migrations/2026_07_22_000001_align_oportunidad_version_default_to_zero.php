<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Align `oportunidad.version` default with the CSV/legacy convention.
 *
 * The original opportunity migration didn't define a `version` column.
 * `add_versioning_columns_to_oportunidad` (2026_07_10) declared it as
 * `default(0)` to match the CSV importer convention (root = version 0,
 * first real version = version 1 with codigo "… v1").
 *
 * But the column was already present on prod with default 1 (likely from
 * a previous draft of the migration that ran before being edited to 0),
 * so the `if (! in_array('version', $columns))` check on 2026_07_10
 * skipped the creation and the default never matched the convention.
 *
 * New opportunities created via the API were therefore getting
 * version = 1, while opportunities imported from CSV got version = 0
 * explicitly. This inconsistency was the root cause of the discrepancy
 * observed when calling CrearVersionOportunidadUseCase (it computes the
 * next version as max(version) + 1).
 *
 * This migration:
 *   1) Changes the column default to 0 (so new API-created opportunities
 *      match the importer convention).
 *   2) Backfills rows that already exist: any row whose codigo does NOT
 *      carry a " vN" / "-VN" suffix (the root of a family) and currently
 *      has version = 1 is downgraded to version = 0. We restrict to
 *      version = 1 to be conservative (we do not rewrite rows whose
 *      version was set explicitly by an importer or human).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `oportunidad` MODIFY COLUMN `version` INT(11) NOT NULL DEFAULT 0');

        // Backfill: downgrade "root" rows that ended up with version=1.
        // A root is the row whose codigo has no " vN" / "-VN" suffix.
        DB::statement("
            UPDATE `oportunidad`
            SET `version` = 0
            WHERE `version` = 1
              AND `codigo` NOT REGEXP '[\\s\\-]+[vV][0-9]+\$'
        ");
    }

    public function down(): void
    {
        // Best-effort rollback: only the default change; we don't try to
        // restore the historical version=1 on roots.
        DB::statement('ALTER TABLE `oportunidad` MODIFY COLUMN `version` INT(11) NOT NULL DEFAULT 1');
    }
};
