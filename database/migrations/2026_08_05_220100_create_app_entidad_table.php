<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot: which apps each entity has contracted.
     *
     * This is the source of truth for "this entity uses this app". Users
     * get access to apps transitively via `entidad_usuario` (their entity
     * memberships).
     */
    public function up(): void
    {
        Schema::create('app_entidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('entidad_id')->constrained('entidad')->cascadeOnDelete();
            $table->date('fecha_contrato')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->enum('estado', ['Activo', 'Suspendido', 'Cancelado', 'Trial'])->default('Activo');
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'entidad_id'], 'idx_app_entidad_unique');
            $table->index('estado', 'idx_app_entidad_estado');
            $table->index(['entidad_id', 'estado'], 'idx_app_entidad_entidad_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_entidad');
    }
};
