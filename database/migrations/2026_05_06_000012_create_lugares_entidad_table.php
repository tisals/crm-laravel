<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lugares_entidad', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entidad_id');
            $table->string('area_oficina', 100)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('direccion_adicional', 255)->nullable();
            $table->string('ciudad_cod', 10)->nullable();
            $table->unsignedBigInteger('contacto_id')->nullable();
            $table->string('estado', 50)->default('Activo');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('entidad_id')->references('id')->on('entidad')->cascadeOnDelete();
            $table->foreign('ciudad_cod')->references('cod_municipio')->on('ciudades')->nullOnDelete();
            $table->foreign('contacto_id')->references('id')->on('contacto')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lugares_entidad');
    }
};
