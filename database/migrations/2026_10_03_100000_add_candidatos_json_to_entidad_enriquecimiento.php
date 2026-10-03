<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR3 of `complementar-entidad` — entity-empresa-enrichment D1 + D2.
 *
 * Adds the `candidatos_json` JSON column on the `entidad_enriquecimiento`
 * annex table so the homonimia branch can persist the list of candidates
 * surfaced by the `EmpresaEnriquecimientoNecesitaSeleccion` event.
 *
 * The migration is idempotent — running it twice on the same schema
 * is a no-op (`hasColumn()` guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('entidad_enriquecimiento')) {
            return;
        }

        if (Schema::hasColumn('entidad_enriquecimiento', 'candidatos_json')) {
            return;
        }

        Schema::table('entidad_enriquecimiento', function (Blueprint $table) {
            // JSON column — nullable because most rows will not have a candidate list.
            $table->json('candidatos_json')->nullable()->after('enriquecimiento_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('entidad_enriquecimiento')) {
            return;
        }

        if (! Schema::hasColumn('entidad_enriquecimiento', 'candidatos_json')) {
            return;
        }

        Schema::table('entidad_enriquecimiento', function (Blueprint $table) {
            $table->dropColumn('candidatos_json');
        });
    }
};