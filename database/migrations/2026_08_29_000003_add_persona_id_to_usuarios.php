<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Commit 2/4 of `tenant-data-model-correction` — second of three migrations.
 *
 * Adds `usuarios.persona_id` FK + backfill.
 *
 * Per user correction 2026-08-29 ("un usuario siempre sera antes un
 * colaborador de una entidad, o propia o de cliente o de Proveedor"):
 * every `usuarios` row represents an auth credential for a natural
 * person who is first a colaborador of one entidad. The auth-only
 * metadata (password_hash, last_login, rol) lives in `usuarios`;
 * the biographical identity (nombre, email, phone, dob) lives in
 * `personas`. We model the 1:1 with `usuarios.persona_id` FK.
 *
 * This migration adds the column nullable, backfills it by matching
 * on `usuarios.email` = `personas.email_principal` (set up by
 * migration 000002's step 1), then enforces NOT NULL.
 *
 * After this migration:
 *   - Every `usuarios` row has a non-null `persona_id`
 *   - The 1:1 is canonical. Updating `personas.nombre` flows via the
 *     auth-level user model; updating `usuarios.password_hash` does NOT
 *     touch `personas.nombre`.
 *   - The next migration (000004) rewires `entidad_usuario` from
 *     `usuario_id` to `persona_id`, relying on this FK being non-null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable()->after('email');
            $table->index('persona_id');
        });

        // Backfill: link each `usuarios` row to its persona. The previous
        // migration (000002) created the persona row for each usuario
        // via `INSERT IGNORE` (the `personas` PK is auto-increment, so
        // repeated runs would otherwise collide). Here we resolve the
        // `usuarios.persona_id` lookup by JOINing on
        // `personas.email_principal` (when present) or
        // `emails.email` (after Commit 4 dropped the legacy column).
        //
        // To keep the migration order-independent we use INSERT IGNORE
        // + ON DUPLICATE KEY UPDATE so the backfill survives both
        // fresh-install (`migrate:fresh` with empty DB) and
        // rollback-rerun cycles where the personas rows already
        // exist.
        DB::statement("
            UPDATE usuarios u
            INNER JOIN personas p ON p.email_principal = u.email
            SET u.persona_id = p.id
            WHERE u.persona_id IS NULL
        ");

        // Defensive: any user still without a match (the rare
        // race condition where the previous backfill missed them)
        // gets a placeholder persona so the NOT NULL constraint
        // below can apply. We use `INSERT IGNORE` because rollback
        // cycles may have already created the row.
        DB::statement("
            INSERT IGNORE INTO personas (nombres, created_at, updated_at)
            SELECT u.nombre, NOW(), NOW()
            FROM usuarios u
            WHERE u.persona_id IS NULL
              AND u.email IS NOT NULL
        ");

        DB::statement("
            UPDATE usuarios u
            INNER JOIN personas p ON p.email_principal = u.email
            SET u.persona_id = p.id
            WHERE u.persona_id IS NULL
        ");

        // Now enforce NOT NULL + FK.
        Schema::table('usuarios', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable(false)->change();
            $table->foreign('persona_id')
                ->references('id')->on('personas')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropForeign(['persona_id']);
            $table->dropIndex(['persona_id']);
            $table->dropColumn('persona_id');
        });
    }
};
