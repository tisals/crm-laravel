<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-C (Phase 1c): add `perfil` column to `app_entidad` (REQ-HPBN-001).
 *
 * Hermes needs per-profile configuration (`setter-safe-health`,
 * `setter-tis`, `setter-alejandro`, `marketing-sailus`,
 * `sst-support-safe-health`). We add a single nullable column so the
 * existing `app_entidad` pivot can carry both the legacy pivot (perfil
 * = NULL) and the Hermes-bound rows (perfil = '<slug>'). The unique
 * invariant `UNIQUE(app_id, entidad_id)` (idx_app_entidad_unique) is
 * preserved unchanged (R-5).
 *
 * Schema (per design.md §3.2 target schema + spec REQ-HPBN-001):
 *  - `perfil VARCHAR(100) NULL` — additive, no FK (free-form slug)
 *  - `idx_app_entidad_perfil` — index for "find all Hermes bindings"
 *
 * Reversibility: `down()` drops the index + the column. Existing
 * `app_entidad` rows keep `perfil = NULL` after migration (REQ-HPBN-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_entidad', function (Blueprint $table) {
            $table->string('perfil', 100)
                ->nullable()
                ->after('estado');

            $table->index('perfil', 'idx_app_entidad_perfil');
        });
    }

    public function down(): void
    {
        Schema::table('app_entidad', function (Blueprint $table) {
            $table->dropIndex('idx_app_entidad_perfil');
            $table->dropColumn('perfil');
        });
    }
};