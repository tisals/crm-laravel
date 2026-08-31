<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR-C (Phase 1c): add the `tipo_oferta` column to `detalle_oportunidad`.
 *
 * REQ-DOP-001 (per spec `detalle-oportunidad-modificado/spec.md`):
 *   - `tipo_oferta VARCHAR(50) NULL DEFAULT 'servicio'` after `concepto`
 *   - Existing rows backfilled to 'servicio' via one-line UPDATE
 *   - Reversible: `down()` drops the column
 *
 * The backfill runs in `up()` BEFORE Laravel commits, so post-migration
 * the column is fully populated (no NULLs) — this keeps REQ-DOP-001's
 * "no NULLs" invariant observable in tests AND in dev DB.
 *
 * REQ-DOP-002 enum validation lives in the Form Request, not the DB.
 * The DB column accepts any VARCHAR(50) value (legacy + new offer types
 * coexist during rollout). The allow-list is enforced at the API edge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_oportunidad', function (Blueprint $table) {
            $table->string('tipo_oferta', 50)
                ->nullable()
                ->default('servicio')
                ->after('concepto');
        });

        // Backfill: every pre-existing row gets 'servicio' (every
        // detalle_oportunidad before this migration was a service quote).
        // The WHERE clause is defensive — if the column was somehow already
        // populated, we don't overwrite explicit values.
        DB::statement("UPDATE `detalle_oportunidad` SET `tipo_oferta` = 'servicio' WHERE `tipo_oferta` IS NULL");
    }

    public function down(): void
    {
        Schema::table('detalle_oportunidad', function (Blueprint $table) {
            $table->dropColumn('tipo_oferta');
        });
    }
};
