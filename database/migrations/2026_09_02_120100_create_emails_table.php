<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 3 of `tenant-data-model-correction` — shared `emails` table.
 *
 * Per spec/design decisions:
 *   - D6: `tipo` strict ENUM (`personal`, `trabajo`, `otro`)
 *   - D7: `es_principal` + `valid_from`/`valid_to` temporal
 *   - D12: entity-or-person (FKs + triggers, see telefonos migration
 *          for why CHECK constraints don't compose with FKs on MariaDB)
 *
 * Replaces inline `personas.email_principal` and `entidad.email`
 * columns. Legacy columns stay for now (Commit 4 drop later).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE `emails` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `persona_id` BIGINT UNSIGNED NULL,
                `entidad_id` BIGINT UNSIGNED NULL,
                `email` VARCHAR(255) NOT NULL,
                `tipo` ENUM('personal','trabajo','otro') NOT NULL,
                `es_principal` TINYINT(1) NOT NULL DEFAULT 0,
                `valid_from` DATE NULL,
                `valid_to` DATE NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `deleted_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                INDEX `emails_persona_principal_idx` (`persona_id`, `es_principal`),
                INDEX `emails_entidad_principal_idx` (`entidad_id`, `es_principal`),
                CONSTRAINT `emails_persona_id_foreign`
                    FOREIGN KEY (`persona_id`) REFERENCES `personas` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `emails_entidad_id_foreign`
                    FOREIGN KEY (`entidad_id`) REFERENCES `entidad` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `emails_entity_or_person_insert`
            BEFORE INSERT ON `emails`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'emails: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `emails_entity_or_person_update`
            BEFORE UPDATE ON `emails`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'emails: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `emails`');
    }
};
