<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR-A (Phase 1a): extends the `personas` table with columns needed by
 * the iter4 persona model (entity ↔ persona relation + natural/juridica
 * classification + optional apellidos for juridica personas).
 *
 * Schema changes:
 *  - adds `tipo_persona` ENUM('Natural','Juridica') NULL — REQ-PNCE-005
 *    defaults to 'Natural' via the application layer.
 *  - adds `entidad_id` BIGINT UNSIGNED NULL — FK to `entidad.id` with
 *    nullOnDelete (a persona survives the parent entidad being deleted).
 *  - makes `apellidos` nullable — REQ-PNCE (juridica personas don't have
 *    surnames).
 *
 * Reversibility: every change has a matching `down()` clause. The table
 * is currently empty (per verify-report-v3) so no data preservation step
 * is required for `apellidos` (zero rows means zero rows would lose data).
 *
 * Note: doctrine/dbal is not installed in this image, so column mutations
 * use raw SQL (DB::statement) instead of `->change()`. This is portable
 * across MariaDB / MySQL since the column metadata is identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. tipo_persona ENUM. Upper-case values match entidad.tipo_persona.
        Schema::table('personas', function (Blueprint $table) {
            $table->enum('tipo_persona', ['Natural', 'Juridica'])
                ->nullable()
                ->after('nombres');
        });

        // 2. apellidos: drop NOT NULL. Raw SQL (no doctrine/dbal dependency).
        DB::statement('ALTER TABLE `personas` MODIFY `apellidos` VARCHAR(100) NULL');

        // 3. entidad_id FK with nullOnDelete. Created separately to ensure
        //    the ON DELETE clause is honored across Laravel versions.
        Schema::table('personas', function (Blueprint $table) {
            $table->unsignedBigInteger('entidad_id')->nullable()->after('apellidos');
            $table->index('entidad_id');
        });

        Schema::table('personas', function (Blueprint $table) {
            $table->foreign('entidad_id')
                ->references('id')->on('entidad')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Drop FK + index + column (must drop FK before column on MariaDB).
        Schema::table('personas', function (Blueprint $table) {
            $table->dropForeign(['entidad_id']);
            $table->dropIndex(['entidad_id']);
            $table->dropColumn('entidad_id');
        });

        // Restore apellidos to NOT NULL.
        // Safe when table is empty (per verify-report-v3); will throw a
        // constraint violation if null rows exist — that is the correct
        // operator-facing signal to clean up before downgrading.
        DB::statement('ALTER TABLE `personas` MODIFY `apellidos` VARCHAR(100) NOT NULL');

        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('tipo_persona');
        });
    }
};
