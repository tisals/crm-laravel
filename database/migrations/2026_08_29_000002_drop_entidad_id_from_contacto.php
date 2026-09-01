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
        // The column participates in the FK `contacto_entidad_id_foreign`
        // (declared in 2026_05_06_000011_create_contacto_table.php) and
        // the unique key `contacto_entidad_id_email_contacto_unique`.
        // Both must be removed BEFORE the column itself can be dropped.
        DB::statement('DROP INDEX IF EXISTS idx_contacto_entidad_active ON contacto');
        $this->dropForeignKeyIfExists('contacto', 'entidad_id');
        $this->dropUniqueKeyIfExists('contacto', 'contacto_entidad_id_email_contacto_unique');
        DB::statement('ALTER TABLE contacto DROP COLUMN IF EXISTS entidad_id');
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $fkName = "{$table}_{$column}_foreign";
        $exists = DB::select(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [$table, $fkName]
        );
        if (! empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
        }
    }

    private function dropUniqueKeyIfExists(string $table, string $keyName): void
    {
        $exists = DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $keyName]
        );
        if (! empty($exists)) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$keyName}`");
        }
    }

    public function down(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->unsignedBigInteger('entidad_id')->nullable()->after('id');
            $table->index(['entidad_id', 'deleted_at'], 'idx_contacto_entidad_active');
        });
    }
};
