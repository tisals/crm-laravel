<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 3 of `tenant-data-model-correction` — `documentos` table.
 *
 * Per spec/design decisions:
 *   - D14: entity-or-person (extends usuarios or entidad, NOT person-only)
 *
 * `path_storage` is a Mercurio OneDrive URL (D13); the integration
 * that actually writes here lives on the Mercurio side. For now we
 * just store the URL the caller provides.
 *
 * Built via raw SQL + triggers; see telefonos migration for the full
 * rationale (MariaDB 10.11 rejects CHECK constraints when the same
 * columns participate in FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE `documentos` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `persona_id` BIGINT UNSIGNED NULL,
                `entidad_id` BIGINT UNSIGNED NULL,
                `uploaded_by` BIGINT UNSIGNED NULL,
                `tipo_documento` ENUM(
                    'rut','hv','certificacion',
                    'contrato_social','acta_constitutiva','otro'
                ) NOT NULL,
                `nombre_archivo` VARCHAR(255) NOT NULL,
                `path_storage` VARCHAR(500) NOT NULL,
                `mime_type` VARCHAR(100) NULL,
                `size_bytes` BIGINT UNSIGNED NULL,
                `uploaded_at` TIMESTAMP NOT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `deleted_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                INDEX `documentos_persona_tipo_idx` (`persona_id`, `tipo_documento`),
                INDEX `documentos_entidad_tipo_idx` (`entidad_id`, `tipo_documento`),
                CONSTRAINT `documentos_persona_id_foreign`
                    FOREIGN KEY (`persona_id`) REFERENCES `personas` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `documentos_entidad_id_foreign`
                    FOREIGN KEY (`entidad_id`) REFERENCES `entidad` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `documentos_uploaded_by_foreign`
                    FOREIGN KEY (`uploaded_by`) REFERENCES `usuarios` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `documentos_entity_or_person_insert`
            BEFORE INSERT ON `documentos`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'documentos: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `documentos_entity_or_person_update`
            BEFORE UPDATE ON `documentos`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'documentos: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `documentos`');
    }
};
