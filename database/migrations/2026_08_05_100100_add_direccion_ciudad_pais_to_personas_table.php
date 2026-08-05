<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->string('direccion', 200)->nullable()->after('telefono_principal');
            $table->string('ciudad', 100)->nullable()->after('direccion');
            $table->string('pais', 100)->nullable()->after('ciudad');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn(['direccion', 'ciudad', 'pais']);
        });
    }
};
