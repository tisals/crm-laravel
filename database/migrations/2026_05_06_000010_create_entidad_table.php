<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidad', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo_persona', ['Natural', 'Juridica']);
            $table->string('tipo_id', 20)->nullable();
            $table->string('identificacion', 50)->unique();
            $table->string('nombre', 255);
            $table->string('nombre_comercial', 255)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('ciudad_cod', 10)->nullable();
            $table->string('dominio', 255)->nullable();
            $table->string('rut', 255)->nullable();
            $table->string('logo', 255)->nullable();
            $table->string('estado', 50)->default('Activo');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ciudad_cod')->references('cod_municipio')->on('ciudades')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entidad');
    }
};
