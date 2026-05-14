<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->decimal('valor_debito', 15, 2)->default(0);
            $table->decimal('valor_credito', 15, 2)->default(0);
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->foreignId('servicio_id')->nullable()->constrained('servicios')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};
