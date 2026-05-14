<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('banco');
            $table->string('numero_cuenta');
            $table->string('tipo', 20)->default('Ahorros');
            $table->string('estado', 20)->default('Activo');
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
            // No softDeletes — cuentas bancarias son registros permanentes
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas');
    }
};
