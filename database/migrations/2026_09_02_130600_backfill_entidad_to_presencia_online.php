<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `entidad.dominio` + `entidad.red_social_url` →
 * `presencia_online` (entidad_id side).
 *
 * `dominio` (e.g. "polinter.com.co") becomes `tipo='web'`,
 * `plataforma='otro'`, `handle=host part`. `red_social_url` (e.g.
 * "facebook.com/humplast") becomes `tipo='red_social'`,
 * `plataforma` from URL-host heuristic, `handle` from URL path.
 *
 * Idempotent: skips entidades that already have a `presencia_online`
 * row matching the legacy URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. `entidad.dominio` → web presence row.
        DB::statement(<<<'SQL'
            INSERT INTO `presencia_online`
                (`entidad_id`, `tipo`, `plataforma`, `handle`, `url`,
                 `es_principal`, `created_at`, `updated_at`)
            SELECT
                e.id,
                'web',
                'otro',
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(LOWER(TRIM(e.dominio)), '://', -1),
                    '/', 1
                ),
                TRIM(e.dominio),
                1,
                NOW(),
                NOW()
            FROM `entidad` e
            WHERE e.dominio IS NOT NULL
              AND TRIM(e.dominio) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `presencia_online` po
                  WHERE po.entidad_id = e.id
                    AND po.url = TRIM(e.dominio)
                    AND po.deleted_at IS NULL
              )
        SQL);

        // 2. `entidad.red_social_url` → red_social row. The `plataforma`
        //    column is derived from the URL host via a CASE expression:
        //    facebook.com/... → 'facebook', instagram.com/... → 'instagram',
        //    linkedin.com/... → 'linkedin', etc. Anything else falls
        //    through to 'otro'.
        DB::statement(<<<'SQL'
            INSERT INTO `presencia_online`
                (`entidad_id`, `tipo`, `plataforma`, `handle`, `url`,
                 `es_principal`, `created_at`, `updated_at`)
            SELECT
                e.id,
                'red_social',
                CASE LOWER(SUBSTRING_INDEX(
                        SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(TRIM(e.red_social_url)), '://', -1), '/', 1),
                        '.', 1
                    ))
                    WHEN 'facebook'  THEN 'facebook'
                    WHEN 'fb'        THEN 'facebook'
                    WHEN 'instagram' THEN 'instagram'
                    WHEN 'ig'        THEN 'instagram'
                    WHEN 'linkedin'  THEN 'linkedin'
                    WHEN 'twitter'   THEN 'twitter'
                    WHEN 'x'         THEN 'twitter'
                    WHEN 'youtube'   THEN 'youtube'
                    WHEN 'youtu'     THEN 'youtube'
                    WHEN 'tiktok'    THEN 'tiktok'
                    WHEN 'github'    THEN 'github'
                    WHEN 'medium'    THEN 'medium'
                    WHEN 'shopify'   THEN 'shopify'
                    ELSE 'otro'
                END,
                SUBSTRING(
                    SUBSTRING_INDEX(LOWER(TRIM(e.red_social_url)), '://', -1),
                    LOCATE('/',
                        SUBSTRING_INDEX(LOWER(TRIM(e.red_social_url)), '://', -1)
                    ) + 1
                ),
                TRIM(e.red_social_url),
                1,
                NOW(),
                NOW()
            FROM `entidad` e
            WHERE e.red_social_url IS NOT NULL
              AND TRIM(e.red_social_url) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `presencia_online` po
                  WHERE po.entidad_id = e.id
                    AND po.url = TRIM(e.red_social_url)
                    AND po.deleted_at IS NULL
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DELETE FROM `presencia_online` WHERE `created_at` >= ?', ['2026-09-02 13:00:00']);
    }
};
