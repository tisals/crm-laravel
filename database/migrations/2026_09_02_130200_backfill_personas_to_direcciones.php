<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `personas.direccion/ciudad/pais` → `direcciones`
 * (persona_id side).
 *
 * Idempotent: skips personas that already have a `direcciones` row.
 *
 * The legacy `personas.ciudad` column is a free-text VARCHAR(100), not
 * a FK to `ciudades.cod_municipio`. The backfill captures it in the
 * `direcciones.nombre_sede` slot so we don't lose the human-readable
 * value; a future cleanup pass can map it to a real `ciudad_codigo`
 * once the legacy column is dropped. Until then, `ciudad_codigo`
 * stays NULL and the free-text lives in `nombre_sede`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO `direcciones`
                (`persona_id`, `direccion_principal`, `nombre_sede`, `pais`,
                 `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT
                p.id,
                NULLIF(TRIM(p.direccion), ''),
                NULLIF(TRIM(p.ciudad), ''),
                CASE
                    WHEN p.pais IS NOT NULL AND CHAR_LENGTH(TRIM(p.pais)) = 2
                        THEN UPPER(TRIM(p.pais))
                    ELSE NULL
                END,
                'casa',
                1,
                NOW(),
                NOW()
            FROM `personas` p
            WHERE (
                  p.direccion IS NOT NULL AND TRIM(p.direccion) <> ''
               OR p.ciudad   IS NOT NULL AND TRIM(p.ciudad)   <> ''
               OR p.pais     IS NOT NULL AND TRIM(p.pais)     <> ''
              )
              AND NOT EXISTS (
                  SELECT 1 FROM `direcciones` d
                  WHERE d.persona_id = p.id
                    AND d.deleted_at IS NULL
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM `direcciones`
            WHERE `es_principal` = 1
              AND `tipo` = 'casa'
              AND `created_at` >= ?
        SQL, ['2026-09-02 13:00:00']);
    }
};
