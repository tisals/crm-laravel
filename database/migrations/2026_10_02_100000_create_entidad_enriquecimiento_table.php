<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR1 of `complementar-entidad` — entity-empresa-enrichment D1.
 *
 * 1:1 annex table for enrichment data populated by the Python FastMCP
 * server (PR2) and consumed by the Laravel-side enrichment pipeline
 * (PR3–PR5). One row per `entidad`; the UNIQUE index on `entidad_id`
 * enforces the 1:1 contract at the schema level.
 *
 * Per design §5 / spec `entity-empresa-enrichment` Requirement 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entidad_enriquecimiento', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('entidad_id');

            // Enrichment payload (all nullable — the row is built incrementally).
            $table->string('nit', 32)->nullable();
            $table->string('ciiu_codigo', 8)->nullable();
            $table->tinyInteger('clase_riesgo_ul_num')->nullable();
            $table->string('clase_riesgo_ul_desc', 64)->nullable();
            $table->string('sector_economico', 64)->nullable();
            $table->string('fuente_enriquecimiento', 16)->nullable();
            $table->timestamp('enriquecido_at')->nullable();
            $table->string('enriquecimiento_hash', 64)->nullable();

            // Lifecycle: pending (just created), enriched (MCP + Decreto resolved),
            // needs_selection (homonimia), failed (after retries), skipped (Habeas exclude).
            $table->enum('enrichment_status', [
                'pending',
                'enriched',
                'needs_selection',
                'failed',
                'skipped',
            ])->default('pending');

            $table->timestamps();

            // 1:1 contract — one annex row per entidad.
            $table->unique('entidad_id', 'entidad_enriquecimiento_entidad_id_unique');

            // FK + lookup speed.
            $table->foreign('entidad_id', 'entidad_enriquecimiento_entidad_id_foreign')
                ->references('id')->on('entidad')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->index('enriquecido_at', 'entidad_enriquecimiento_enriquecido_at_idx');
            $table->index('enrichment_status', 'entidad_enriquecimiento_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entidad_enriquecimiento');
    }
};