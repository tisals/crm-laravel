<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->json('diagnostico_data')->nullable()->after('estado');
            $table->string('fuente', 100)->nullable()->after('diagnostico_data');
        });
    }

    public function down(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->dropColumn(['diagnostico_data', 'fuente']);
        });
    }
};
