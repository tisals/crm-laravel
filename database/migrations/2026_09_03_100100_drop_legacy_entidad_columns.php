<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 4 of `tenant-data-model-correction` — drop legacy columns on
 * `entidad` now that the canonical `emails` / `telefonos` /
 * `direcciones` / `presencia_online` tables (Commit 3) carry that
 * data, backfilled by the 130000..130600 migrations.
 *
 * Down(): restores the legacy columns (with their FK to
 * `ciudades.cod_municipio`). Same rationale as the matching personas
 * migration: the migration tests in `tests/Feature/Migration/`
 * rely on `migrate:rollback` reaching a pre-Commit 4 state, and
 * `000002`'s up() (which doesn't run here but is invoked when
 * 000002's down → re-up sequence happens during a `migrate:rollback
 * && migrate` cycle) reads `entidad.email` etc. when re-running.
 *
 * Dropped columns:
 *   - `entidad.email`           (replaced by `emails.email`, tipo=trabajo)
 *   - `entidad.telefono`        (replaced by `telefonos.numero`, tipo=trabajo)
 *   - `entidad.direccion`       (replaced by `direcciones.direccion_principal`, tipo=oficina)
 *   - `entidad.ciudad_cod`      (replaced by `direcciones.ciudad_codigo` FK)
 *   - `entidad.dominio`         (replaced by `presencia_online.url`, tipo=web, plataforma=otro)
 *   - `entidad.red_social_url`  (replaced by `presencia_online.url`, tipo=red_social)
 *
 * Not dropped (intentionally kept):
 *   - `entidad.cantidad_empleados` — orthogonal to contact data
 *   - `entidad.rut`, `entidad.logo` — file assets (kept until Mercurio
 *      `cloud_storage` integration absorbs them)
 *   - `entidad.allowed_domains`, `entidad.webhook_*` — multi-tenant
 *      plumbing, not contact data
 */
return new class extends Migration
{
    public function up(): void
    {
        // `entidad.ciudad_cod` has a FK constraint to
        // `ciudades.cod_municipio`. MariaDB does NOT auto-drop the
        // FK when the column is dropped — we have to drop the FK
        // constraint explicitly first, otherwise the COLUMN drop
        // fails with errno 1553 ("Cannot drop index needed in a
        // foreign key constraint").
        DB::statement('ALTER TABLE `entidad` DROP FOREIGN KEY `entidad_ciudad_cod_foreign`');

        DB::statement('ALTER TABLE `entidad` DROP COLUMN IF EXISTS `email`');
        DB::statement('ALTER TABLE `entidad` DROP COLUMN IF EXISTS `telefono`');
        DB::statement('ALTER TABLE `entidad` DROP COLUMN IF EXISTS `direccion`');
        DB::statement('ALTER TABLE `entidad` DROP COLUMN IF EXISTS `ciudad_cod`');
        DB::statement('ALTER TABLE `entidad` DROP COLUMN IF EXISTS `dominio`');
        DB::statement('ALTER TABLE `entidad` DROP COLUMN IF EXISTS `red_social_url`');
    }

    public function down(): void
    {
        // Restore columns in the same shape as the original
        // `2026_05_04_xxxxxx_create_entidad_table` migration. The FK
        // on `ciudad_cod` is recreated LAST so the column exists by
        // the time the FK references it.
        $this->addColumnIfMissing('entidad', 'email', 'VARCHAR(255) NULL');
        $this->addColumnIfMissing('entidad', 'telefono', 'VARCHAR(50) NULL');
        $this->addColumnIfMissing('entidad', 'direccion', 'VARCHAR(255) NULL');
        $this->addColumnIfMissing('entidad', 'ciudad_cod', 'VARCHAR(10) NULL');
        $this->addColumnIfMissing('entidad', 'dominio', 'VARCHAR(255) NULL');
        $this->addColumnIfMissing('entidad', 'red_social_url', 'VARCHAR(255) NULL');

        // Re-add the ciudad_cod FK if it isn't already in place
        // (the up() drops it before dropping the column, so by the
        // time down() runs the FK is gone but the column is restored
        // just above). We skip if the FK already exists from a
        // partial previous down.
        $fkExists = DB::select(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "entidad"
               AND CONSTRAINT_NAME = "entidad_ciudad_cod_foreign"
             LIMIT 1'
        );
        if (empty($fkExists)) {
            DB::statement('ALTER TABLE `entidad`
                ADD CONSTRAINT `entidad_ciudad_cod_foreign`
                FOREIGN KEY (`ciudad_cod`) REFERENCES `ciudades` (`cod_municipio`)
                ON DELETE SET NULL ON UPDATE CASCADE');
        }
    }

    /**
     * Add a column to `$table` only if it doesn't already exist. See
     * matching helper in the personas migration for the rationale.
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
