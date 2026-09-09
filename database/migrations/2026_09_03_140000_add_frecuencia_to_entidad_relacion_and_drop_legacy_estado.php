<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 5.5 of `tenant-data-model-correction` — `frecuencia` +
 * `recurrencia_cada_meses` on `entidad_relacion`, plus dropping the
 * legacy `entidad.estado` and `entidad.cliente_desde` columns.
 *
 * Per user design (2026-09-03): the business-state pivot needs more
 * granularity than just `tipo_relacion`. Different customers have
 * different billing cadences:
 *
 *   - One-shot customers: `frecuencia='unica'`, `recurrencia_cada_meses=NULL`
 *   - 3-month clients: `frecuencia='unica'`, `recurrencia_cada_meses=NULL` + `vigencia_meses=3`
 *     (we kept `vigencia_meses` from the original spec — it's the contract duration)
 *   - Monthly SaaS: `frecuencia='recurrente'`, `recurrencia_cada_meses=1`
 *   - Annual SaaS: `frecuencia='recurrente'`, `recurrencia_cada_meses=12`
 *   - Quarterly: `frecuencia='recurrente'`, `recurrencia_cada_meses=3`
 *
 * The CHECK constraint enforces the relationship between the two
 * fields:
 *   - `frecuencia='unica'` → `recurrencia_cada_meses` MUST be NULL
 *   - `frecuencia='recurrente'` → `recurrencia_cada_meses` MUST be 1..12
 *
 * The `vigencia_meses` column already exists on `entidad_relacion`
 * (from Commit 5). We don't add it here; this migration just adds
 * the two new fields.
 *
 * Down() reverses both changes:
 *   - Drops `frecuencia` + `recurrencia_cada_meses` (in reverse order)
 *   - Restores `entidad.estado` + `entidad.cliente_desde` (with
 *     information_schema guards like Commit 4's down())
 *
 * Drop legacy `entidad.estado`: its semantics now live on the pivot
 * (active pivot row = active entidad). We provide a `getEstadoAttribute()`
 * accessor on the Eloquent `Entidad` model that derives the value
 * from the pivot so callers (Resources, Controllers) don't break.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: add frecuencia + recurrencia_cada_meses to
        // entidad_relacion. We use raw SQL because the CHECK
        // constraint needs to inspect both columns, and the inline
        // form is cleaner than the equivalent ALTER TABLE steps.
        //
        // Note: existing rows are backfilled to `frecuencia='unica'`
        // (the default for the Commit 5 backfilled rows). A future
        // pass can reclassify existing pivot rows based on opportunity
        // history (e.g. multiple ganadas with regular intervals →
        // `recurrente` + `recurrencia_cada_meses` derived from the
        // median gap).
        DB::statement(<<<'SQL'
            ALTER TABLE `entidad_relacion`
                ADD COLUMN `frecuencia` ENUM('unica','recurrente') NOT NULL DEFAULT 'unica' AFTER `effective_to`,
                ADD COLUMN `recurrencia_cada_meses` TINYINT UNSIGNED NULL AFTER `frecuencia`
        SQL);

        // CHECK constraint: when `frecuencia='unica'`, the recurrence
        // must be NULL. When `frecuencia='recurrente'`, the recurrence
        // must be 1..12 (months between cycles).
        //
        // This constraint DOESN'T touch FK columns, so it works
        // inline via ALTER TABLE (the MariaDB 10.11 error 1901 only
        // bites when CHECK inspects FK columns — see Commit 3's
        // telefonos migration for that gotcha).
        DB::statement(<<<'SQL'
            ALTER TABLE `entidad_relacion`
                ADD CONSTRAINT `entidad_relacion_frecuencia_chk`
                CHECK (
                    (`frecuencia` = 'unica'      AND `recurrencia_cada_meses` IS NULL)
                 OR (`frecuencia` = 'recurrente' AND `recurrencia_cada_meses` BETWEEN 1 AND 12)
                )
        SQL);

        // Step 2: drop legacy `entidad.estado` and `entidad.cliente_desde`.
        //
        // `entidad.estado` semantics now live on the pivot: an entidad
        // is "activo" iff it has at least one pivot row with
        // `effective_to IS NULL`. The Eloquent `Entidad` accessor
        // derives that for callers (Controllers, Resources).
        //
        // `entidad.cliente_desde` already migrated to
        // `entidad_relacion.effective_from` (Commit 5 backfill), so
        // dropping it is safe.
        //
        // `entidad.estado` is NOT NULL with a default of 'Activo'; we
        // drop it without an explicit DROP DEFAULT first because
        // MariaDB ignores the default once the column is gone.
        //
        // Commit 8: the DROP is now `IF EXISTS` so the migration is
        // safe to apply AFTER `2026_09_09_120000_drop_legacy_entidad_…`
        // has already removed the columns. That happens on the live
        // DB scenario where Commit 8 ships, the operator runs
        // `migrate`, the new migration drops the columns, and then
        // this older migration's `up()` (which had been pending on
        // the live DB) finally gets a chance to run. Without
        // `IF EXISTS` it would fail with "Unknown column 'estado'".
        DB::statement('ALTER TABLE `entidad` DROP COLUMN IF EXISTS `estado`');
        DB::statement('ALTER TABLE `entidad` DROP COLUMN IF EXISTS `cliente_desde`');
    }

    public function down(): void
    {
        // Reverse in opposite order: restore the dropped columns
        // first (so the add-frecuencia down has a clean target), then
        // drop the new fields.

        $this->addColumnIfMissing('entidad', 'estado', "VARCHAR(50) NOT NULL DEFAULT 'Activo' AFTER `tipo_id`");
        $this->addColumnIfMissing('entidad', 'cliente_desde', 'TIMESTAMP NULL AFTER `estado`');

        DB::statement('ALTER TABLE `entidad_relacion` DROP CONSTRAINT IF EXISTS `entidad_relacion_frecuencia_chk`');
        DB::statement('ALTER TABLE `entidad_relacion` DROP COLUMN IF EXISTS `recurrencia_cada_meses`');
        DB::statement('ALTER TABLE `entidad_relacion` DROP COLUMN IF EXISTS `frecuencia`');
    }

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
