<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-E (Phase 3): add `persona_id` FK column to `contacto` (REQ-PRRL-001).
 *
 * Schema changes:
 *  - `persona_id` BIGINT UNSIGNED NULL, after `entidad_id`
 *  - FK → `personas.id` with `nullOnDelete` (deleting a persona sets
 *    contacto.persona_id to NULL, mirroring the existing `entidad_id`
 *    precedent — REQ-PRRL-006)
 *  - non-unique index `idx_contacto_persona_id` (a persona can be many
 *    contactos across entidades)
 *
 * Additive only: existing `contacto` rows survive with `persona_id = NULL`.
 * The pre-existing `entidad_id` FK and the soft-delete column are untouched.
 *
 * Reversibility: down() drops the FK, the index, and the column in the
 * reverse order added. A `migrate:rollback --step=3` reverses the full
 * PR-E package cleanly (REQ-PRRL-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')
                ->nullable()
                ->after('entidad_id');

            $table->index('persona_id', 'idx_contacto_persona_id');
        });

        Schema::table('contacto', function (Blueprint $table) {
            $table->foreign('persona_id', 'contacto_persona_id_foreign')
                ->references('id')->on('personas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->dropForeign('contacto_persona_id_foreign');
            $table->dropIndex('idx_contacto_persona_id');
            $table->dropColumn('persona_id');
        });
    }
};
