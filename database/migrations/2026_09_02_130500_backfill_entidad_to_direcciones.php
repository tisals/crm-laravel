<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `entidad.direccion/ciudad_cod` → `direcciones`
 * (entidad_id side).
 *
 * Idempotent: skips entidades that already have a `direcciones` row.
 *
 * The legacy `entidad.ciudad_cod` is a VARCHAR(10) — matches the
 * `ciudades.cod_municipio` PK type, so we map it directly to
 * `direcciones.ciudad_codigo` (the FK target). That's a real FK
 * (unlike `personas.ciudad` which is free-text and went into
 * `nombre_sede`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO `direcciones`
                (`entidad_id`, `direccion_principal`, `ciudad_codigo`,
                 `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT
                e.id,
                NULLIF(TRIM(e.direccion), ''),
                NULLIF(TRIM(e.ciudad_cod), ''),
                'oficina',
                1,
                NOW(),
                NOW()
            FROM `entidad` e
            WHERE (
                  e.direccion  IS NOT NULL AND TRIM(e.direccion)  <> ''
               OR e.ciudad_cod IS NOT NULL AND TRIM(e.ciudad_cod) <> ''
              )
              AND NOT EXISTS (
                  SELECT 1 FROM `direcciones` d
                  WHERE d.entidad_id = e.id
                    AND d.deleted_at IS NULL
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM `direcciones`
            WHERE `es_principal` = 1
              AND `tipo` = 'oficina'
              AND `created_at` >= ?
        SQL, ['2026-09-02 13:00:00']);
    }
};
