<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-E (Phase 3): add `persona_id` FK column to `colaboradores`
 * (REQ-PRRL-002, RQ-1).
 *
 * Schema changes:
 *  - `persona_id` BIGINT UNSIGNED NULL, after `usuario_id`
 *  - FK → `personas.id` with `nullOnDelete`
 *  - UNIQUE index `idx_colaboradores_persona_id_unique` (RQ-1: 1 persona
 *    per colaborador — a collaborator is conceptually a single individual,
 *    matching the existing `colaboradores.identificacion` UNIQUE invariant)
 *
 * Additive only: existing `colaboradores` rows survive with
 * `persona_id = NULL`. The pre-existing `usuario_id` UNIQUE and
 * `identificacion` UNIQUE are untouched.
 *
 * Reversibility: down() drops the FK, the UNIQUE index, and the column in
 * the reverse order added. A `migrate:rollback --step=3` reverses the full
 * PR-E package cleanly (REQ-PRRL-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')
                ->nullable()
                ->after('usuario_id');

            $table->unique('persona_id', 'idx_colaboradores_persona_id_unique');
        });

        Schema::table('colaboradores', function (Blueprint $table) {
            $table->foreign('persona_id', 'colaboradores_persona_id_foreign')
                ->references('id')->on('personas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropForeign('colaboradores_persona_id_foreign');
            $table->dropUnique('idx_colaboradores_persona_id_unique');
            $table->dropColumn('persona_id');
        });
    }
};