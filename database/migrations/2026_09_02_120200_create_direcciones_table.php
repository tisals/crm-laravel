<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 3 of `tenant-data-model-correction` — shared `direcciones` table.
 *
 * Per spec/design decisions:
 *   - D6: `tipo` strict ENUM (`casa`, `oficina`, `sucursal`, `facturacion`, `otro`)
 *   - D7: `es_principal` + `valid_from`/`valid_to` temporal
 *   - D12: entity-or-person (FKs + triggers, see telefonos migration)
 *
 * `nombre_sede` per user request: entidades can name each branch
 * ("Sucursal Norte", "Sucursal Centro") for multi-location entities.
 *
 * Replaces inline `personas.direccion/ciudad/pais` and
 * `entidad.direccion/ciudad_cod` columns. Legacy columns stay for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE `direcciones` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `persona_id` BIGINT UNSIGNED NULL,
                `entidad_id` BIGINT UNSIGNED NULL,
                `ciudad_codigo` VARCHAR(10) NULL,
                `direccion_principal` VARCHAR(255) NULL,
                `direccion_complementaria` VARCHAR(255) NULL,
                `nombre_sede` VARCHAR(100) NULL,
                `codigo_postal` VARCHAR(20) NULL,
                `pais` VARCHAR(2) NULL,
                `tipo` ENUM('casa','oficina','sucursal','facturacion','otro') NOT NULL,
                `es_principal` TINYINT(1) NOT NULL DEFAULT 0,
                `valid_from` DATE NULL,
                `valid_to` DATE NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `deleted_at` TIMESTAMP NULL,
                PRIMARY KEY (`id`),
                INDEX `direcciones_persona_principal_idx` (`persona_id`, `es_principal`),
                INDEX `direcciones_entidad_principal_idx` (`entidad_id`, `es_principal`),
                INDEX `direcciones_ciudad_idx` (`ciudad_codigo`),
                CONSTRAINT `direcciones_persona_id_foreign`
                    FOREIGN KEY (`persona_id`) REFERENCES `personas` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `direcciones_entidad_id_foreign`
                    FOREIGN KEY (`entidad_id`) REFERENCES `entidad` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `direcciones_ciudad_codigo_foreign`
                    FOREIGN KEY (`ciudad_codigo`) REFERENCES `ciudades` (`cod_municipio`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `direcciones_entity_or_person_insert`
            BEFORE INSERT ON `direcciones`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'direcciones: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER `direcciones_entity_or_person_update`
            BEFORE UPDATE ON `direcciones`
            FOR EACH ROW
            BEGIN
                IF NEW.persona_id IS NULL AND NEW.entidad_id IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'direcciones: at least one of persona_id / entidad_id is required';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `direcciones`');
    }
};
