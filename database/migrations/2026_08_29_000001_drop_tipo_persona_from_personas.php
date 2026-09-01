<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commit 1 of `tenant-data-model-correction`.
 *
 * Drops `personas.tipo_persona` ENUM column. Rationale:
 *
 *   - Personas Jurídicas live in the `entidad` table, which already has
 *     its own `tipo_persona` ENUM('Natural','Juridica') (added by the
 *     historical Phase 1c migration). Personas is for Personas Naturales only.
 *
 *   - PR-A (commit `ac98c1b`) over-scoped personas by also covering
 *     Jurídica rows. 0 rows in `minerva` use `tipo_persona='Juridica'`
 *     (verified 2026-08-29); all 2121 personas rows are `Natural`.
 *
 *   - This migration removes the redundant ENUM from `personas` so the
 *     domain is unambiguous: only `entidad` carries `tipo_persona`.
 *
 * Reversibility: restores the ENUM column (NULL default). Existing rows
 * get NULL until next backfill — the down() helper assigns `Natural` for
 * the rows that came in via BackfillPersonasFromContactoUseCase.
 *
 * See `openspec/changes/tenant-data-model/` for the full design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('tipo_persona');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->enum('tipo_persona', ['Natural', 'Juridica'])
                ->default(null)
                ->after('email_principal');
        });

        // Restore default value for Natural-only personas (the only kind
        // PR-A ever inserted).
        \DB::statement("UPDATE personas SET tipo_persona='Natural' WHERE tipo_persona IS NULL");
    }
};
