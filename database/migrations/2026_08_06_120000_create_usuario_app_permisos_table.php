<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-(user, app) scoped permissions. Adds app-level overrides on top
     * of the existing global `permisos(rol_id, vista)` model.
     *
     * A row grants the user the given `vista` for the given `app_id`. The
     * effective permission is the UNION of (global rol permission) and
     * (any matching row here). SuperAdmin bypass (`vista='*'`) is checked
     * in `MultiAppRbacService` — not stored in this table.
     *
     * See ADD-AUTH-001 §6.5 (Vista de Datos).
     */
    public function up(): void
    {
        Schema::create('usuario_app_permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('vista', 100);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique (user, app, vista) — one grant per scope.
            $table->unique(['usuario_id', 'app_id', 'vista'], 'idx_uap_unique');
            // Index for fast user-level lookups.
            $table->index(['usuario_id', 'app_id'], 'idx_uap_user_app');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_app_permisos');
    }
};
