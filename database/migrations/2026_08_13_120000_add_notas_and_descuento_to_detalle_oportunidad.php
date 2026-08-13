<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds `notas` (free-text note) and `descuento` (percentage discount) columns
     * to `detalle_oportunidad`. These columns were missing from the original
     * schema and caused the PUT silent-drop bug (request fields not persisted).
     *
     * - `notas`:    nullable text  — internal notes per detail line
     * - `descuento`: nullable decimal(12,2) — percentage discount (0..100)
     *
     * The migration is idempotent via Schema::hasColumn guards.
     */
    public function up(): void
    {
        Schema::table('detalle_oportunidad', function (Blueprint $table) {
            if (! Schema::hasColumn('detalle_oportunidad', 'notas')) {
                $table->text('notas')->nullable()->after('descripcion');
            }
            if (! Schema::hasColumn('detalle_oportunidad', 'descuento')) {
                $table->decimal('descuento', 12, 2)->nullable()->default(0)->after('vr_unitario');
            }
        });
    }

    public function down(): void
    {
        Schema::table('detalle_oportunidad', function (Blueprint $table) {
            if (Schema::hasColumn('detalle_oportunidad', 'notas')) {
                $table->dropColumn('notas');
            }
            if (Schema::hasColumn('detalle_oportunidad', 'descuento')) {
                $table->dropColumn('descuento');
            }
        });
    }
};
