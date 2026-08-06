<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CQRS-Lite read model for user identity. One row per user, holding the
     * pre-computed bundle `{user, apps[], permisos scoped}` that the
     * `/me/identity` endpoint serves in a single SELECT.
     *
     * Refreshed nightly by `crm:refresh-user-identity-snapshot` and on-demand
     * after any permission mutation. `is_stale` flips to 1 when a mutation
     * has invalidated the snapshot; the next read recomputes and resets the
     * flag. See ADD-AUTH-001 §6.5 (Vista de Datos).
     */
    public function up(): void
    {
        Schema::create('user_identity_snapshot', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->json('payload');
            $table->string('scope_label', 20)->default('v1');
            $table->timestamp('computed_at')->nullable();
            $table->boolean('is_stale')->default(false);

            $table->index('computed_at', 'idx_uis_computed_at');
            $table->index('is_stale', 'idx_uis_is_stale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identity_snapshot');
    }
};
