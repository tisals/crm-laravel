<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the `deleted_at` column to `apps`.
     *
     * The original `create_apps_table` migration (220000) forgot to call
     * `$table->softDeletes()` even though the `App` model uses the
     * SoftDeletes trait. This causes ANY query against `apps` (including
     * `App::updateOrCreate` used in `AppsCatalogSeeder`) to throw
     * `Column not found: 1054 Unknown column 'apps.deleted_at' in 'WHERE'`.
     *
     * Idempotent: checks information_schema before adding.
     */
    public function up(): void
    {
        if ($this->columnExists('apps', 'deleted_at')) {
            return;
        }

        Schema::table('apps', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! $this->columnExists('apps', 'deleted_at')) {
            return;
        }

        Schema::table('apps', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }

    private function columnExists(string $table, string $column): bool
    {
        $database = DB::connection()->getDatabaseName();

        $result = DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$database, $table, $column]
        );

        return ((int) ($result->cnt ?? 0)) > 0;
    }
};
