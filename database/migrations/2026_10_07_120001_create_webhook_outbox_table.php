<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->json('payload_json');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status', 16)->default('pending');
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_outbox_status');
            $table->index('next_retry_at', 'idx_outbox_next_retry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_outbox');
    }
};
