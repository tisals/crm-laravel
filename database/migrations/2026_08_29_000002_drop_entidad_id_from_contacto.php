<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commit 2/4 of `tenant-data-model-correction` — first of three migrations.
 *
 * Drops `contacto.entidad_id` index + column (idempotent).
 *
 * Per user correction 2026-08-29, contacto.persona_id FK (from PR-E)
 * is correct (CTI: contacto is a ROLE-table that inherits identity from
 * the persona). The direct `contacto.entidad_id` column is wrong because
 * it bypasses the `entidad_persona` pivot that should mediate WHICH
 * entidad the contacto belongs to and IN WHAT CAPACITY.
 *
 * Idempotency: this migration uses `DROP INDEX IF EXISTS` and
 * `DROP COLUMN IF EXISTS` (MariaDB 10.1+) so re-running is safe even
 * if a previous run partially executed.
 *
 * Going forward, the link contacto ↔ entidad goes through:
 *
 *   contacto.persona_id  ─┐
 *                          ├─►  entidad_persona  ◄─┐
 *   contacto (extends)    ─┘   (categoria FK)    │
 *                                                ▼
 *                                              entidad
 *
 * Reversibility: restores the column + index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1 — create personas rows for usuarios that have none
        // (needed because migration 000003 will add usuarios.persona_id FK).
        // Idempotent: LEFT JOIN + IS NULL filter skips already-linked usuarios.
        DB::statement("
            INSERT INTO personas (
                identificacion_tipo, identificacion_numero,
                nombres, apellidos,
                email_principal, created_at, updated_at
            )
            SELECT
                NULL, NULL,
                u.nombre, NULL,
                u.email, NOW(), NOW()
            FROM usuarios u
            LEFT JOIN personas p ON p.email_principal = u.email
            WHERE p.id IS NULL
              AND u.email IS NOT NULL
        ");

        // Step 2 — drop contacto.entidad_id index + column (idempotent).
        DB::statement('DROP INDEX IF EXISTS idx_contacto_entidad_active ON contacto');
        DB::statement('ALTER TABLE contacto DROP COLUMN IF EXISTS entidad_id');
    }

    public function down(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->unsignedBigInteger('entidad_id')->nullable()->after('id');
            $table->index(['entidad_id', 'deleted_at'], 'idx_contacto_entidad_active');
        });
    }
};
