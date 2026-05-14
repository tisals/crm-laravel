<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_id', 20)->nullable();
            $table->string('identificacion', 50)->unique();
            $table->string('nombres', 150)->nullable();
            $table->string('apellidos', 150)->nullable();
            $table->string('profesion', 150)->nullable();
            $table->string('especialidad', 150)->nullable();
            $table->decimal('iva', 5, 2)->nullable();
            $table->decimal('retenciones', 5, 2)->nullable();
            $table->string('ciudad_cod', 10)->nullable();
            $table->date('fecha_registro')->nullable();
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
        Schema::dropIfExists('proveedores');
    }
};
