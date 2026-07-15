<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds versioning columns to oportunidad table for opportunity versioning.
 *
 * These columns are intentionally added in a separate migration so that
 * `php artisan migrate` picks them up on production — the original
 * pipeline migration (2026_06_04_124355) is already marked as run, so
 * any column additions inside it would be skipped silently.
 *
 * After this migration runs, run `php artisan crm:version-opportunities`
 * to populate `is_latest`, `version`, and `parent_id` for existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = collect(Schema::getConnection()->select(
            "SHOW COLUMNS FROM oportunidad"
        ))->pluck('Field')->all();

        Schema::table('oportunidad', function (Blueprint $table) use ($columns) {
            // parent_id points to the latest version's id for superseded rows
            if (! in_array('parent_id', $columns)) {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('pipeline_etapa_id')
                    ->constrained('oportunidad')
                    ->onDelete('set null');
            }

            // version: 0 for base (no suffix), N for "vN" suffix
            if (! in_array('version', $columns)) {
                $table->integer('version')->default(0)->after('parent_id');
            }

            // is_latest: true only for the highest version of each opportunity family
            if (! in_array('is_latest', $columns)) {
                $table->boolean('is_latest')->default(true)->after('version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('oportunidad', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'version', 'is_latest']);
        });
    }
};