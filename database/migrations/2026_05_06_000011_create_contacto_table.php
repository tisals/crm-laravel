<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->string('nombres', 150);
            $table->string('apellidos', 150);
            $table->string('area', 100)->nullable();
            $table->string('cargo', 100)->nullable();
            $table->string('tel_contacto', 50)->nullable();
            $table->string('movil', 50)->nullable();
            $table->string('email_contacto', 255)->nullable();
            $table->string('email_secundario', 255)->nullable();
            $table->string('rol', 100)->nullable();
            $table->string('etapa', 50)->nullable();
            $table->string('estado', 50)->default('Activo');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('entidad_id')->references('id')->on('entidad')->nullOnDelete();
            $table->unique(['entidad_id', 'email_contacto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacto');
    }
};
