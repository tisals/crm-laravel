<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_servicio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detalle_srv_id')->nullable()->constrained('detalle_servicios')->nullOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->foreignId('contacto_id')->nullable()->constrained('contacto')->nullOnDelete();
            $table->text('descripcion')->nullable();
            $table->text('objetivo')->nullable();
            $table->string('ubicacion')->nullable();
            $table->dateTime('fecha_desde')->nullable();
            $table->dateTime('fecha_hasta')->nullable();
            $table->decimal('valor', 15, 2)->default(0);
            $table->string('estado', 50)->default('Pendiente');
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_servicio');
    }
};
