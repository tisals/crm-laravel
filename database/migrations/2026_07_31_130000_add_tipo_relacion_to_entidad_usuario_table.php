<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('entidad_usuario', 'tipo_relacion')) {
            return;
        }

        Schema::table('entidad_usuario', function (Blueprint $table) {
            $table->string('tipo_relacion', 250)->nullable()->after('entidad_id');
            $table->longText('metadata')->nullable();
            $table->index('tipo_relacion');
        });

        // Backfill: lo existente → 'asignado'
        DB::table('entidad_usuario')->whereNull('tipo_relacion')->update(['tipo_relacion' => 'asignado']);
    }

    public function down(): void
    {
        Schema::table('entidad_usuario', function (Blueprint $table) {
            $table->dropIndex(['tipo_relacion']);
            $table->dropColumn(['tipo_relacion', 'metadata']);
        });
    }
};
