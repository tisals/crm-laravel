<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 3 of `tenant-data-model-correction` — shared `telefonos` table.
 *
 * Per spec/design decisions:
 *   - D6: `tipo` strict ENUM
 *   - D7: `es_principal` + `valid_from`/`valid_to` temporal
 *   - D12: entity-or-person (FKs to personas + entidad; trigger enforces
 *          "at least one of persona_id / entidad_id")
 *
 * Built via raw SQL because MariaDB 10.11 rejects inline CHECK
 * constraints when there's also a FOREIGN KEY that references the
 * same columns ("Function or expression cannot be used in the CHECK
 * clause"). The CHECK form works with FKs on OTHER columns, but here
 * the FK target columns are the very ones the CHECK inspects, so we
 * fall back to BEFORE INSERT/UPDATE triggers.
 *
 * Replaces inline `personas.telefono_principal` and `entidad.telefono`
 * columns. Legacy columns stay for now (Commit 4 drop later).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE `telefonos` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `persona_id` BIGINT UNSIGNED NULL,
                `entidad_id` BIGINT UNSIGNED NULL,
                `numero` VARCHAR(50) NOT NULL,
                `indicativo` VARCHAR(10) NULL,
                `tipo` ENUM('movil','fijo','trabajo','whatsapp','otro') NOT NULL,
                `es_principal` TINYINT(1) NOT NULL DEFAULT 0,
                `valid_from` DATE NULL,
                `valid_to` DATE NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `deleted_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                INDEX `telefonos_persona_principal_idx` (`persona_id`, `es_principal`),
                INDEX `telefonos_entidad_principal_idx` (`entidad_id`, `es_principal`),
                CONSTRAINT `telefonos_persona_id_foreign`
                    FOREIGN KEY (`persona_id`) REFERENCES `personas` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `telefonos_entidad_id_foreign`
                    FOREIGN KEY (`entidad_id`) REFERENCES `entidad` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Triggers enforce the entity-or-person invariant. We can't
        // use a CHECK constraint because MariaDB rejects it when the
        // same columns participate in FKs (error 1901). The trigger
        // raises a SIGNAL SQLSTATE '45000' with the same semantics so
        // Eloquent receives a QueryException just like a CHECK would.
        DB::statement(<<<'SQL'
            CREATE TRIGGER `telefonos_entity_or_person_insert`
            BEFORE INSERT ON `telefonos`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'telefonos: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `telefonos_entity_or_person_update`
            BEFORE UPDATE ON `telefonos`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'telefonos: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `telefonos`');
    }
};
