<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commit 2/4 of `tenant-data-model-correction` — third migration.
 *
 * Renames `entidad_usuario` → `entidad_persona`, retargets the pivot FK
 * from `usuarios.id` to `personas.id`, and adds a `categoria` ENUM.
 *
 * Per user correction 2026-08-29: every `usuarios` is "primero un
 * colaborador de una entidad" — so the pivot mediates the relationship
 * between `personas` (natural persons) and `entidad`s, not between
 * `usuarios` (auth credentials) and `entidad`s.
 *
 * The three ENUM values:
 *
 *   - `dependencia`  — natural person is an EMPLOYEE of this entidad
 *                      (default). Patricia Moreno as Tecnoinnsoft employee.
 *   - `asignacion`   — natural person is ASSIGNED as comercial to this
 *                      entidad (transient). Comercial assigned to client's account.
 *   - `delegacion`   — natural person has DELEGATED admin powers across
 *                      this entidad (cross-tenant admin). Admin@tecnoinnsoft.dev
 *                      acting on Tecnoinnsoft SAS BIC or Deseguridad.net.
 *
 * Execution (5 steps, in transaction):
 *   1. Add `persona_id` nullable column (backfilled in step 2)
 *   2. Backfill from `usuarios.persona_id` (set by migration 000003)
 *   3. Drop `usuario_id` FK + column
 *   4. Add `persona_id` NOT NULL FK + index, drop old PK, add composite PK
 *   5. Add `categoria` ENUM with default 'dependencia', backfill admin
 *      rows to 'delegacion' (id=5 is the only cross-tenant admin in current data)
 *
 * Reversibility: restores table name + structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add persona_id column nullable. ──────────────────────────────
        Schema::table('entidad_usuario', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable()->after('usuario_id');
        });

        // ── 2. Backfill from usuarios.persona_id. ──────────────────────────
        DB::statement("
            UPDATE entidad_usuario eu
            INNER JOIN usuarios u ON u.id = eu.usuario_id
            SET eu.persona_id = u.persona_id
            WHERE eu.persona_id IS NULL AND u.persona_id IS NOT NULL
        ");

        // ── 3. Drop the FK to usuarios and the usuario_id column. ─────────
        Schema::table('entidad_usuario', function (Blueprint $table) {
            $table->dropForeign(['usuario_id']);
            $table->dropPrimary();
            $table->dropColumn('usuario_id');
        });

        // ── 4. persona_id NOT NULL + FK + composite PK on
        //       (persona_id, entidad_id). The composite matches the
        //       one-user-per-entidad invariant the old PK had, but
        //       pivots on natural-person identity. ─────────────────────
        Schema::table('entidad_usuario', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable(false)->change();
            $table->foreign('persona_id')
                ->references('id')->on('personas')
                ->cascadeOnDelete();
            $table->primary(['persona_id', 'entidad_id']);
            $table->index('entidad_id');  // already exists but be explicit
        });

        // ── 5. Rename the table. ────────────────────────────────────────
        Schema::rename('entidad_usuario', 'entidad_persona');

        // ── 6. Add `categoria` ENUM with default 'dependencia'. ──────────
        Schema::table('entidad_persona', function (Blueprint $table) {
            $table->enum('categoria', ['dependencia', 'asignacion', 'delegacion'])
                ->default('dependencia')
                ->after('persona_id');
        });

        // ── 7. Backfill admin@tecnoinnsoft.dev rows to 'delegacion'. ────
        DB::statement("
            UPDATE entidad_persona ep
            INNER JOIN personas p ON p.id = ep.persona_id
            INNER JOIN usuarios u ON u.persona_id = p.id
            SET ep.categoria = 'delegacion'
            WHERE u.email = 'admin@tecnoinnsoft.dev'
              AND ep.categoria = 'dependencia'
        ");
    }

    public function down(): void
    {
        Schema::table('entidad_persona', function (Blueprint $table) {
            $table->dropColumn('categoria');
        });

        Schema::rename('entidad_persona', 'entidad_usuario');

        Schema::table('entidad_usuario', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropForeign(['persona_id']);
            $table->dropColumn('persona_id');
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->foreign('usuario_id')
                ->references('id')->on('usuarios')
                ->cascadeOnDelete();
            $table->primary(['usuario_id', 'entidad_id']);
        });
    }
};
