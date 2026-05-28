<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actividad_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('tipo', 50); // created, updated, deleted, login
            $table->string('descripcion', 500);
            $table->string('modelo_type', 100)->nullable();
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->timestamps();

            $table->index(['modelo_type', 'modelo_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividad_log');
    }
};
