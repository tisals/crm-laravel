<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicio_app', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servicio_id')->constrained('servicios')->cascadeOnDelete();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->enum('estado', ['activo', 'suspendido', 'vencido'])->default('activo');
            $table->date('fecha_activacion')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->timestamps();

            $table->unique(['servicio_id', 'app_id']);
            $table->index(['estado', 'fecha_vencimiento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicio_app');
    }
};
