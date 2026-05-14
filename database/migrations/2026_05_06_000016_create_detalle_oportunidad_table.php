<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_oportunidad', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('oportunidad_id');
            $table->unsignedBigInteger('producto_id');
            $table->string('concepto', 255)->nullable();
            $table->string('medida', 10)->default('Und');
            $table->decimal('cantidad', 10, 2);
            $table->decimal('vr_unitario', 15, 2);
            $table->decimal('iva', 15, 2)->default(0);
            $table->decimal('vr_total', 15, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('oportunidad_id')->references('id')->on('oportunidad')->cascadeOnDelete();
            $table->foreign('producto_id')->references('id')->on('productos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_oportunidad');
    }
};
