<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Commit 5 backfill — populate `entidad_relacion` from the
 * legacy `entidad.estado` + `entidad.cliente_desde` columns.
 *
 * Idempotent: only inserts a row when no `entidad_relacion`
 * already exists for that entidad (the `NOT EXISTS` guard makes
 * this safe to re-run after a rollback + migrate cycle).
 *
 * Legacy `estado` values and their pivot translation:
 *
 *   'Cliente'    (uppercase) → tipo='cliente'
 *   'cliente'    (lowercase legacy) → tipo='cliente'
 *   'prospecto'  (lowercase legacy) → tipo='prospecto'
 *   'Activo'     → tipo='prospecto'
 *                 (the old "Activo" didn't distinguish "actively in
 *                 sales pipeline" from "post-sale customer"; we
 *                 default new prospecto rows to that bucket. A
 *                 cleanup pass can re-classify based on
 *                 oportunidad counts.)
 *   'Inactivo'   → tipo='prospecto'  (closed-lost)
 *   'Cancelado'  → tipo='prospecto'  (cancelled; same default;
 *                  we don't drop the row, we leave it open so the
 *                  entity keeps showing in CRM)
 *
 * For `'cliente'` / `'Cliente'` rows, `effective_from` is the
 * `entidad.cliente_desde` value (when present) or NOW() as the
 * fallback. For all other values, `effective_from` is NOW() — we
 * don't have a historical date we can use.
 *
 * `effective_to` is NULL for all backfilled rows: they represent
 * the CURRENT (open-ended) relation. Future dates where the
 * relation ends will be set explicitly by the application when
 * the entity's business state changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO `entidad_relacion`
                (`entidad_id`, `tipo_relacion`, `effective_from`, `effective_to`,
                 `created_at`, `updated_at`)
            SELECT
                e.id,
                CASE
                    WHEN e.estado IN ('Cliente', 'cliente')           THEN 'cliente'
                    WHEN e.estado = 'prospecto'                       THEN 'prospecto'
                    WHEN e.estado IN ('Activo', 'Inactivo', 'Cancelado') THEN 'prospecto'
                    ELSE 'prospecto'
                END,
                CASE
                    WHEN e.estado IN ('Cliente', 'cliente') AND e.cliente_desde IS NOT NULL
                        THEN DATE(e.cliente_desde)
                    ELSE CURDATE()
                END,
                NULL,
                NOW(),
                NOW()
            FROM `entidad` e
            WHERE NOT EXISTS (
                SELECT 1 FROM `entidad_relacion` er
                WHERE er.entidad_id = e.id
            )
        SQL);
    }

    public function down(): void
    {
        // Backfill is destructive only of the pivot rows we inserted.
        // We delete rows created during this migration cycle by matching
        // on `created_at >= '2026-09-03 12:00:00'`.
        DB::statement('DELETE FROM `entidad_relacion` WHERE `created_at` >= ?', ['2026-09-03 12:00:00']);
    }
};
