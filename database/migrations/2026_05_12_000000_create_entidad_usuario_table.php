<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidad_usuario', function (Blueprint $table) {
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('entidad_id')->constrained('entidad')->cascadeOnDelete();
            $table->primary(['usuario_id', 'entidad_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entidad_usuario');
    }
};
