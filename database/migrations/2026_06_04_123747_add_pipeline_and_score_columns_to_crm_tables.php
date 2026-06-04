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
        Schema::table('contacto', function (Blueprint $table) {
            $table->integer('score')->nullable()->default(0)->after('estado');
        });

        Schema::table('oportunidad', function (Blueprint $table) {
            $table->string('pipeline', 50)->default('Llegada')->after('contacto_id');
            $table->string('estado', 50)->default('Borrador')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->dropColumn('score');
        });

        Schema::table('oportunidad', function (Blueprint $table) {
            $table->dropColumn('pipeline');
            $table->enum('estado', ['Borrador', 'Enviada', 'Aceptada', 'Rechazada', 'Ganada', 'Perdida'])->default('Borrador')->change();
        });
    }
};
