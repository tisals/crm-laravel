<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seguimiento', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('oportunidad_id')->nullable();
            $table->unsignedBigInteger('contacto_id')->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->enum('tipo', ['Llamada', 'Correo', 'Reunion', 'Nota', 'Otro']);
            $table->date('fecha');
            $table->time('hora')->nullable();
            $table->dateTime('fecha_fin')->nullable();
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('autor_id')->nullable();
            $table->enum('estado', ['Pendiente', 'Completado', 'Cancelado'])->default('Pendiente');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('oportunidad_id')->references('id')->on('oportunidad')->nullOnDelete();
            $table->foreign('contacto_id')->references('id')->on('contacto')->nullOnDelete();
            $table->foreign('entidad_id')->references('id')->on('entidad')->nullOnDelete();
            $table->foreign('autor_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seguimiento');
    }
};
