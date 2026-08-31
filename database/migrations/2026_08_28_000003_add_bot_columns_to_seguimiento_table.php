<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR-B (Phase 1b): extend the `seguimiento` table with bot-fact columns.
 *
 * REQ-ISCF-002 — when a Mercury / SAIlus Agent bot creates a seguimiento row, it
 * stamps the fact type, confidence score, and source profile. Operators
 * can then filter "show me all bot-generated follow-ups with confidence
 * > 0.8".
 *
 * All three columns MUST be nullable: existing rows have no bot fact,
 * and human-authored rows never set them. No FK because the values are
 * free-form slugs / score, not references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seguimiento', function (Blueprint $table) {
            $table->string('bot_fact_type', 50)
                ->nullable()
                ->after('tipo');
            $table->decimal('bot_confidence', 4, 3)
                ->nullable()
                ->after('bot_fact_type');
            $table->string('bot_source_profile', 50)
                ->nullable()
                ->after('bot_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('seguimiento', function (Blueprint $table) {
            $table->dropColumn(['bot_fact_type', 'bot_confidence', 'bot_source_profile']);
        });
    }
};
