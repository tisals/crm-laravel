<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds composite indexes for the most-frequent query patterns.
     *
     * Targets:
     * - contacto: list active contacts for an entity (CRMPage, DirectorioPage, ContactosPage)
     * - oportunidad: list opportunities by entity+state (CRMPage kanban)
     * - seguimiento: list by entity + order by fecha (SeguimientosPage, calendar)
     * - persona: list active personas (PersonasPage)
     */
    public function up(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            // Covers: WHERE entidad_id = ? AND deleted_at IS NULL
            $table->index(['entidad_id', 'deleted_at'], 'idx_contacto_entidad_active');
        });

        Schema::table('oportunidad', function (Blueprint $table) {
            // Covers: WHERE entidad_id = ? AND estado = ? AND deleted_at IS NULL
            $table->index(['entidad_id', 'estado', 'deleted_at'], 'idx_oportunidad_entidad_estado_active');
        });

        Schema::table('seguimiento', function (Blueprint $table) {
            // Covers: WHERE entidad_id IN (...) AND ORDER BY fecha DESC
            // The user-scope filter goes via entidad_usuario, NOT via autor_id.
            $table->index(['entidad_id', 'fecha'], 'idx_seguimiento_entidad_fecha');
        });

        Schema::table('personas', function (Blueprint $table) {
            // Covers: WHERE deleted_at IS NULL ORDER BY created_at DESC
            $table->index(['deleted_at', 'created_at'], 'idx_personas_active_recent');
        });
    }

    public function down(): void
    {
        Schema::table('contacto', function (Blueprint $table) {
            $table->dropIndex('idx_contacto_entidad_active');
        });

        Schema::table('oportunidad', function (Blueprint $table) {
            $table->dropIndex('idx_oportunidad_entidad_estado_active');
        });

        Schema::table('seguimiento', function (Blueprint $table) {
            $table->dropIndex('idx_seguimiento_entidad_fecha');
        });

        Schema::table('personas', function (Blueprint $table) {
            $table->dropIndex('idx_personas_active_recent');
        });
    }
};
