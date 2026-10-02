<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * PR1 of `complementar-entidad` — D1 idempotent column guard.
 *
 * `cantidad_empleados` is already part of `entidad.$fillable` on
 * `App\Models\Entidad` and was historically added to the schema in a
 * prior iteration. This migration is a no-op guard so PR1 stays
 * reversible without dropping a column that may carry production data.
 *
 * If a future migration run finds the column missing (e.g. on a
 * downstream database that branched off before the column was added),
 * the migration adds it. Otherwise it does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('entidad', 'cantidad_empleados')) {
            Schema::table('entidad', function ($table) {
                $table->integer('cantidad_empleados')->nullable()->after('linea_negocio');
            });
        }
    }

    public function down(): void
    {
        // No-op: do not drop a column that existed before this migration
        // ran. Dropping it would erase production data and break historical
        // app behaviour (the column is referenced by Entidad::$fillable).
    }
};