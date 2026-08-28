<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-B (Phase 1b): create the `bot_sessions` table for the iter4 bot funnel.
 *
 * REQ-ISCF-001 ÔÇö Mercury setter funnel + SST support sessions.
 *
 * Schema (per design.md ┬º3.2 target schema + spec REQ-ISCF-001):
 *  - 11 data columns + 2 timestamp columns + 1 soft-delete column = 14 cols
 *  - 3 nullable FKs (entidad, persona, oportunidad), all `nullOnDelete`
 *  - 2 composite indexes (brand_slug+profile_slug, estado+started_at)
 *  - 1 UNIQUE index on session_key (bot-generated UUID)
 *
 * Rationale: mirrors Mercury's `marketing_agent_sessions` shape. The
 * `metadata` JSON column allows per-bot extensions without future schema
 * churn. SoftDeletes preserves the audit trail even after a session ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_key', 100)->unique();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->string('brand_slug', 50);
            $table->string('profile_slug', 50);
            $table->unsignedBigInteger('persona_id')->nullable();
            $table->unsignedBigInteger('oportunidad_id')->nullable();
            $table->enum('estado', ['Activa', 'Completada', 'Abandonada', 'Escalada'])
                ->default('Activa');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_slug', 'profile_slug']);
            $table->index(['estado', 'started_at']);

            $table->foreign('entidad_id')
                ->references('id')->on('entidad')
                ->nullOnDelete();
            $table->foreign('persona_id')
                ->references('id')->on('personas')
                ->nullOnDelete();
            $table->foreign('oportunidad_id')
                ->references('id')->on('oportunidad')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_sessions');
    }
};
