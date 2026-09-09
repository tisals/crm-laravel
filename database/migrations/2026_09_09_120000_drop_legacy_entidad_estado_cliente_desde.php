<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commit 8 of `tenant-data-model-correction` — DEFINITIVE drop of the
 * legacy `entidad.estado` and `entidad.cliente_desde` columns.
 *
 * Earlier commits (5 / 5.5) removed the application-layer references
 * to these columns and replaced them with the canonical
 * `entidad_relacion` pivot (Commit 5) and a derived `getEstadoAttribute()`
 * accessor on the Eloquent `Entidad` model (Commit 5.5). However the
 * columns themselves remained in the DB schema for the iterative
 * rollout. Commit 8 drops them at the migration level so the post-Commit
 * 8 schema is the canonical one.
 *
 * Why the migration is `IF EXISTS`-guarded:
 *
 *   The migration is intended to be safe to run on:
 *
 *     (a) A live DB at the post-Commit 7 state where `entidad.estado`
 *         and `entidad.cliente_desde` STILL exist (because the
 *         earlier `2026_09_03_140000_*` migration was either pending
 *         or rolled back).
 *
 *     (b) A fresh DB at the post-Commit 5.5 state where the earlier
 *         `2026_09_03_140000_*` migration has ALREADY been applied
 *         and dropped the columns.
 *
 *     (c) A DB mid-rollback chain where the `down()` of a later
 *         migration has re-added the columns.
 *
 *   The defensive `Schema::hasColumn()` check makes all three paths
 *   succeed without manual operator intervention.
 *
 * Down() deliberately INTENTIONALLY raises — Commit 8's contract is
 * "irreversible" (per the change proposal). The `down()` would have to
 * re-add the columns AND backfill them from the pivot, which is
 * non-trivial and out of scope for the Commit 8 surface. Operators who
 * need to revert Commit 8 should restore from a pre-Commit 8 backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: drop `entidad.estado`. This column was a VARCHAR(50)
        // NOT NULL with the legacy ENUM-style values ('Cliente',
        // 'Activo', 'Inactivo', 'prospecto', etc.). It was already
        // dropped in `2026_09_03_140000_*` on a clean fresh migrate;
        // this migration ensures it is dropped on the live DB which
        // might still have the column when Commit 8 ships.
        //
        // We use `DROP COLUMN IF EXISTS` (added in MariaDB 10.0.2)
        // to keep the migration idempotent against the `down()` of
        // earlier migrations that may have re-added the column.
        if (Schema::hasColumn('entidad', 'estado')) {
            DB::statement('ALTER TABLE `entidad` DROP COLUMN `estado`');
        }

        // Step 2: drop `entidad.cliente_desde`. This column was a
        // TIMESTAMP NULL that stored the date an entity became a
        // customer. Its semantics now live on the `entidad_relacion`
        // pivot row's `effective_from` column (Commit 5 backfill).
        //
        // We use the same defensive guard as `estado`.
        if (Schema::hasColumn('entidad', 'cliente_desde')) {
            DB::statement('ALTER TABLE `entidad` DROP COLUMN `cliente_desde`');
        }
    }

    public function down(): void
    {
        // Commit 8 is intentionally one-way. The `down()` would have
        // to (a) re-add the columns, (b) backfill them from the
        // `entidad_relacion` pivot, AND (c) rewire the Eloquent
        // accessors that Commit 5.5 added to derive `estado` from
        // the pivot. None of that is in scope for the Commit 8
        // surface; operators who need to roll back should restore
        // from a pre-Commit 8 backup.
        throw new \RuntimeException(
            'Commit 8 is irreversible. The legacy entidad.estado and '
            .'entidad.cliente_desde columns cannot be re-created from '
            .'this migration alone — the canonical state now lives on '
            .'the entidad_relacion pivot (Commits 5 + 5.5). Restore '
            .'from a pre-Commit 8 backup if a rollback is required.'
        );
    }
};
