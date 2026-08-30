<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-E (Phase 3): add `persona_id` FK column to `proveedores`
 * (REQ-PRRL-003).
 *
 * Schema changes:
 *  - `persona_id` BIGINT UNSIGNED NULL, after `identificacion`
 *  - FK → `personas.id` with `nullOnDelete`
 *  - non-unique index `idx_proveedores_persona_id` (a persona can be
 *    multiple proveedores across vendor roles — different roles per
 *    entity are allowed)
 *
 * Additive only: existing `proveedores` rows survive with
 * `persona_id = NULL`. The pre-existing `identificacion` UNIQUE is
 * untouched.
 *
 * Reversibility: down() drops the FK, the index, and the column in the
 * reverse order added. A `migrate:rollback --step=3` reverses the full
 * PR-E package cleanly (REQ-PRRL-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')
                ->nullable()
                ->after('identificacion');

            $table->index('persona_id', 'idx_proveedores_persona_id');
        });

        Schema::table('proveedores', function (Blueprint $table) {
            $table->foreign('persona_id', 'proveedores_persona_id_foreign')
                ->references('id')->on('personas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropForeign('proveedores_persona_id_foreign');
            $table->dropIndex('idx_proveedores_persona_id');
            $table->dropColumn('persona_id');
        });
    }
};