<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaboradores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id')->nullable()->unique();
            $table->string('nombres', 150);
            $table->string('apellidos', 150);
            $table->string('tipo_id', 20)->nullable();
            $table->string('identificacion', 50)->unique();
            $table->string('cargo', 100)->nullable();
            $table->string('area', 100)->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->date('fecha_retiro')->nullable();
            $table->string('contrato', 100)->nullable();
            $table->string('estado', 50)->default('Activo');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaboradores');
    }
};
