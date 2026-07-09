<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('oportunidad', function (Blueprint $table) {
            $table->string('frecuencia', 20)->nullable()->after('pipeline_etapa_id')
                ->comment('Frecuencia de facturación: mensual, trimestral, semestral, anual');
            $table->unsignedSmallInteger('duracion_meses')->nullable()->after('frecuencia')
                ->comment('Duración del contrato en meses (para cálculo de LTV)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oportunidad', function (Blueprint $table) {
            $table->dropColumn(['frecuencia', 'duracion_meses']);
        });
    }
};
