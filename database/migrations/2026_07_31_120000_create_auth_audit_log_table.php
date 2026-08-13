<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('event', 50);
            $table->string('email', 150);
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('ip', 45);
            $table->string('user_agent', 500)->nullable();
            $table->string('request_id', 50)->nullable();
            $table->longText('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_audit_log');
    }
};
