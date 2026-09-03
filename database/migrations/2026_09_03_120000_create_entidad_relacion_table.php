<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 5 of `tenant-data-model-correction` — `entidad_relacion` pivot.
 *
 * Per spec / D17-C decision: each entidad's business-state
 * (cliente/prospecto/propia/proveedor) lives on its own pivot row
 * with temporal validity, NOT as a single `entidad.estado`
 * VARCHAR. This migration creates the table and indexes; a
 * separate migration (130000_backfill_entidad_estado_to_entidad_relacion)
 * backfills from the legacy `entidad.estado` column.
 *
 * Built via raw SQL because MariaDB 10.11 is finicky with CHECK
 * constraints interleaved with FKs / indexes in `Schema::create`
 * (same gotcha as Commit 3's `telefonos` migration — see
 * `2026_09_02_120000_create_telefonos_table.php` for the
 * rationale). The schema here is plain enough that `Schema::create`
 * would work, but we stay consistent with the Commit 3 pattern.
 *
 * The temporal overlap policy ("no two open rows with the same
 * tipo_relacion on the same day") is enforced at the application
 * layer, NOT via a DB CHECK — overlapping types (e.g. a customer
 * that was also a proveedor at a different point in time) are
 * legitimate, and we'd rather let the application model those
 * than fight the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE `entidad_relacion` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entidad_id` BIGINT UNSIGNED NOT NULL,
                `tipo_relacion` ENUM('cliente','prospecto','propia','proveedor') NOT NULL,
                `effective_from` DATE NOT NULL,
                `effective_to` DATE NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `updated_by` BIGINT UNSIGNED NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                INDEX `entidad_relacion_entidad_from_idx`
                    (`entidad_id`, `effective_from` DESC),
                INDEX `entidad_relacion_tipo_open_idx`
                    (`tipo_relacion`, `effective_to`),
                CONSTRAINT `entidad_relacion_entidad_id_foreign`
                    FOREIGN KEY (`entidad_id`) REFERENCES `entidad` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `entidad_relacion_created_by_foreign`
                    FOREIGN KEY (`created_by`) REFERENCES `usuarios` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `entidad_relacion_updated_by_foreign`
                    FOREIGN KEY (`updated_by`) REFERENCES `usuarios` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `entidad_relacion`');
    }
};
