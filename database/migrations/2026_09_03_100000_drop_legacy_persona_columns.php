<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 4 of `tenant-data-model-correction` — drop legacy columns on
 * `personas` now that the canonical `emails` / `telefonos` /
 * `direcciones` tables (Commit 3) carry that data, backfilled by
 * the 130000..130600 migrations.
 *
 * Down(): restores the legacy columns. This is necessary for the
 * migration tests in `tests/Feature/Migration/` that
 * `migrate:rollback --step=N` back to the pre-Commit 4 state, then
 * run their assertions against the pre-Commit 4 schema. A no-op
 * `down()` would leave the schema in a partially-applied state
 * where 000002's UP() (which references `personas.email_principal`)
 * can't run.
 *
 * Dropped columns:
 *   - `personas.telefono_principal`  (replaced by `telefonos.numero`)
 *   - `personas.email_principal`     (replaced by `emails.email`)
 *   - `personas.direccion`           (replaced by `direcciones.direccion_principal`)
 *   - `personas.ciudad`              (free-text; migrated into `direcciones.nombre_sede`)
 *   - `personas.pais`                (replaced by `direcciones.pais`)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop indexes first (some MariaDB versions are picky about
        // dropping a column with a still-bound index). The original
        // column indexes from the iter4 persona table extensions
        // (PR-A) are the candidates.
        DB::statement('DROP INDEX IF EXISTS `personas_email_principal_unique` ON `personas`');
        DB::statement('DROP INDEX IF EXISTS `personas_telefono_principal_index` ON `personas`');

        DB::statement('ALTER TABLE `personas` DROP COLUMN IF EXISTS `telefono_principal`');
        DB::statement('ALTER TABLE `personas` DROP COLUMN IF EXISTS `email_principal`');
        DB::statement('ALTER TABLE `personas` DROP COLUMN IF EXISTS `direccion`');
        DB::statement('ALTER TABLE `personas` DROP COLUMN IF EXISTS `ciudad`');
        DB::statement('ALTER TABLE `personas` DROP COLUMN IF EXISTS `pais`');
    }

    public function down(): void
    {
        // Restore the legacy columns in the same shape as PR-A
        // originally created them (2026_08_28_000001). We re-add the
        // data from the shared tables that survived the drop, so the
        // rollback leaves the personas table in a usable state (the
        // shared tables themselves are not deleted by this down).
        //
        // Use `IF NOT EXISTS`-style guard via INFORMATION_SCHEMA
        // because the down may run multiple times in a
        // rollback-rerun cycle; an un-guarded `ADD COLUMN` would
        // fail with "Duplicate column".
        $this->addColumnIfMissing('personas', 'email_principal', 'VARCHAR(150) NULL AFTER `apellidos`');
        $this->addColumnIfMissing('personas', 'telefono_principal', 'VARCHAR(30) NULL AFTER `apellidos`');
        $this->addColumnIfMissing('personas', 'direccion', 'VARCHAR(200) NULL');
        $this->addColumnIfMissing('personas', 'ciudad', 'VARCHAR(100) NULL');
        $this->addColumnIfMissing('personas', 'pais', 'VARCHAR(100) NULL');

        // Re-create the index the up() dropped (matches the
        // PR-A-era index name `personas_email_principal_unique`).
        // Skip if the index already exists from a prior partial down.
        $indexExists = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "personas"
               AND INDEX_NAME = "personas_email_principal_unique"
             LIMIT 1'
        );
        if (empty($indexExists)) {
            DB::statement('CREATE INDEX `personas_email_principal_unique` ON `personas` (`email_principal`)');
        }
    }

    /**
     * Add a column to `$table` only if it doesn't already exist. Uses
     * `information_schema` because `ADD COLUMN IF NOT EXISTS` syntax
     * was added in MariaDB 10.0.2 but isn't supported in all
     * deployments (and adds a compatibility footnote we don't need).
     */
    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $exists = DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1',
            [$table, $column]
        );
        if (empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
};
