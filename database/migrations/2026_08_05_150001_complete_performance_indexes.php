<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the indexes that the previous `add_performance_indexes` migration
     * failed to create due to a wrong column name (`usuario_id` instead of
     * the actual schema column). The previous migration ran the first two
     * `Schema::table` calls successfully but failed on `seguimiento`, leaving
     * the DB in a partial state.
     *
     * This migration:
     * - Adds idx_seguimiento_entidad_fecha (entity + date for user-scope queries)
     * - Adds idx_personas_active_recent (soft-delete + created_at for PersonasPage)
     *
     * Idempotent: if the indexes already exist, this is a no-op via try/catch
     * to keep deploys safe on already-migrated environments.
     */
    public function up(): void
    {
        // seguimiento: entity + date for user-scope filtering
        if (! $this->indexExists('seguimiento', 'idx_seguimiento_entidad_fecha')) {
            Schema::table('seguimiento', function (Blueprint $table) {
                $table->index(['entidad_id', 'fecha'], 'idx_seguimiento_entidad_fecha');
            });
        }

        // personas: active + recent
        if (! $this->indexExists('personas', 'idx_personas_active_recent')) {
            Schema::table('personas', function (Blueprint $table) {
                $table->index(['deleted_at', 'created_at'], 'idx_personas_active_recent');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('seguimiento', 'idx_seguimiento_entidad_fecha')) {
            Schema::table('seguimiento', function (Blueprint $table) {
                $table->dropIndex('idx_seguimiento_entidad_fecha');
            });
        }

        if ($this->indexExists('personas', 'idx_personas_active_recent')) {
            Schema::table('personas', function (Blueprint $table) {
                $table->dropIndex('idx_personas_active_recent');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::connection()->getDatabaseName();

        $result = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return ((int) ($result->cnt ?? 0)) > 0;
    }
};
