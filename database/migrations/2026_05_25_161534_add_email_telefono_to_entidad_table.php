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
            $table->string('email', 255)->nullable()->after('dominio');
            $table->string('telefono', 50)->nullable()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->dropColumn(['email', 'telefono']);
        });
    }
};
