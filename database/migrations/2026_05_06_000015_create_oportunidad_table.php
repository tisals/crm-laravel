<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oportunidad', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->unsignedBigInteger('entidad_id');
            $table->unsignedBigInteger('contacto_id')->nullable();
            $table->date('fecha');
            $table->string('fuente_canal', 100)->nullable();
            $table->enum('estado', ['Borrador', 'Enviada', 'Aceptada', 'Rechazada', 'Ganada', 'Perdida'])->default('Borrador');
            $table->text('observaciones')->nullable();
            $table->text('aclaraciones')->nullable();
            $table->unsignedInteger('validez_oferta')->nullable();
            $table->string('tiempo_entrega', 255)->nullable();
            $table->string('forma_pago', 255)->nullable();
            $table->string('garantia', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('entidad_id')->references('id')->on('entidad')->cascadeOnDelete();
            $table->foreign('contacto_id')->references('id')->on('contacto')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oportunidad');
    }
};
