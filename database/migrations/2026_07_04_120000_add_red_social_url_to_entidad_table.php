<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `red_social_url` column to `entidad` table.
     *
     * El seeder de oportunidades.csv lee la columna `DOMINIO` que en algunos
     * casos contiene URLs de Facebook/Instagram/LinkedIn en vez de dominios
     * reales. Para no contaminar la columna `dominio` (que se usa para
     * matching de duplicados), separamos las URLs sociales en su propia
     * columna.
     *
     * Backfill: para entidades creadas por el seeder que ya tengan un valor
     * de red social guardado en `dominio`, lo moveremos a `red_social_url`
     * como parte del seeder (no de esta migración).
     */
    public function up(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->string('red_social_url', 255)->nullable()->after('dominio');
        });
    }

    public function down(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->dropColumn('red_social_url');
        });
    }
};
