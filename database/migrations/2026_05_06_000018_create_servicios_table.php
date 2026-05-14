<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oportunidad_id')->nullable()->unique()->constrained('oportunidad')->nullOnDelete();
            $table->foreignId('entidad_id')->constrained('entidad');
            $table->string('nombre');
            $table->decimal('vr_servicio', 15, 2)->default(0);
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->foreignId('prestador_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->string('estado', 50)->default('Nuevo');
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios');
    }
};
