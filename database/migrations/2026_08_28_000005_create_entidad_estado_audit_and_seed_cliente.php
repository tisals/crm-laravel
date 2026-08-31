<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR-C (Phase 1c): seed `entidad.estado='Cliente'` for SaaS tenants
 * (REQ-ENT-002, REQ-ISCF-004, AD-6, AD-11).
 *
 * Two-step migration:
 *
 *   1. Create the `entidad_estado_audit` side-table. Per AD-11 we need a
 *      per-row history of "which entidad was promoted, from which estado,
 *      when" so the rollback can restore previous_estado accurately.
 *
 *   2. INSERT-SELECT into the audit table for every qualifying entity
 *      (has any app_entidad.estado IN ('Activo','Trial') AND not already
 *      'Cliente'), THEN UPDATE the entidad row.
 *
 * Idempotent: re-running `php artisan migrate` on top of the freshly
 * seeded DB touches 0 rows (the WHERE clause excludes estado='Cliente'
 * already-promoted entities).
 *
 * `down()` restores previous_estado per row from the audit table, then
 * drops the audit table itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Side-table audit (AD-11). Guarded so re-running `up()`
        //    against a partially-applied state is a no-op for the create.
        if (! Schema::hasTable('entidad_estado_audit')) {
            Schema::create('entidad_estado_audit', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entidad_id');
                $table->string('previous_estado', 50);
                $table->string('new_estado', 50);
                $table->timestamp('changed_at')->useCurrent();
                $table->string('migration_name', 100);

                $table->index('entidad_id');
            });
        }

        // 2. Capture the qualifying entities BEFORE the UPDATE so the
        //    audit row records the original estado. The `estado <> 'Cliente'`
        //    guard makes the INSERT idempotent: a re-run finds no rows
        //    that still need to be audited.
        DB::statement("
            INSERT INTO `entidad_estado_audit`
                (`entidad_id`, `previous_estado`, `new_estado`, `changed_at`, `migration_name`)
            SELECT
                e.id,
                e.estado,
                'Cliente',
                CURRENT_TIMESTAMP,
                'seed_entidad_estado_cliente'
            FROM `entidad` e
            WHERE EXISTS (
                SELECT 1 FROM `app_entidad` ae
                WHERE ae.entidad_id = e.id
                AND ae.estado IN ('Activo', 'Trial')
            )
            AND e.estado <> 'Cliente'
        ");

        // 3. Promote qualifying entities to estado='Cliente'. Also guarded.
        DB::statement("
            UPDATE `entidad` e
            SET e.estado = 'Cliente'
            WHERE EXISTS (
                SELECT 1 FROM `app_entidad` ae
                WHERE ae.entidad_id = e.id
                AND ae.estado IN ('Activo', 'Trial')
            )
            AND e.estado <> 'Cliente'
        ");
    }

    public function down(): void
    {
        // Restore previous_estado per row, then drop the audit table.
        DB::statement("
            UPDATE `entidad` e
            JOIN `entidad_estado_audit` a ON a.entidad_id = e.id
            SET e.estado = a.previous_estado
            WHERE a.migration_name = 'seed_entidad_estado_cliente'
        ");

        Schema::dropIfExists('entidad_estado_audit');
    }
};
