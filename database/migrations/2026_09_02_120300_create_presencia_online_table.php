<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 3 of `tenant-data-model-correction` — shared `presencia_online` table.
 *
 * Unifies the legacy `entidad.dominio` (treated as `tipo=web`,
 * `plataforma=otro`) and `entidad.red_social_url` (treated as
 * `tipo=red_social`, `plataforma` from URL host heuristic) into a
 * single typed table.
 *
 * Per spec/design decisions:
 *   - D6: `tipo` + `plataforma` strict ENUMs
 *   - D7: `es_principal` + `valid_from`/`valid_to` temporal
 *   - D12: entity-or-person (FKs + triggers, see telefonos migration)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE `presencia_online` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `persona_id` BIGINT UNSIGNED NULL,
                `entidad_id` BIGINT UNSIGNED NULL,
                `tipo` ENUM('red_social','foro','web','blog','ecommerce') NOT NULL,
                `plataforma` ENUM(
                    'linkedin','twitter','instagram','facebook',
                    'youtube','tiktok','github',
                    'portfolio','shopify','wordpress','medium','otro'
                ) NOT NULL,
                `handle` VARCHAR(100) NULL,
                `url` VARCHAR(500) NOT NULL,
                `es_principal` TINYINT(1) NOT NULL DEFAULT 0,
                `valid_from` DATE NULL,
                `valid_to` DATE NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `deleted_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                INDEX `presencia_online_persona_tipo_idx` (`persona_id`, `tipo`),
                INDEX `presencia_online_entidad_tipo_idx` (`entidad_id`, `tipo`),
                CONSTRAINT `presencia_online_persona_id_foreign`
                    FOREIGN KEY (`persona_id`) REFERENCES `personas` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `presencia_online_entidad_id_foreign`
                    FOREIGN KEY (`entidad_id`) REFERENCES `entidad` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `presencia_online_entity_or_person_insert`
            BEFORE INSERT ON `presencia_online`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'presencia_online: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `presencia_online_entity_or_person_update`
            BEFORE UPDATE ON `presencia_online`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'presencia_online: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `presencia_online`');
    }
};
