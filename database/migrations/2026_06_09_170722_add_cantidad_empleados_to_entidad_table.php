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
        Schema::table('entidad', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_empleados')->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->dropColumn('cantidad_empleados');
        });
    }
};
