<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servicios', function (Blueprint $table) {
            $table->string('activation_token', 64)->nullable()->unique();
            $table->string('plan_id', 50)->nullable();
            $table->string('tier', 50)->nullable();
            $table->string('subscription_id', 100)->nullable();
            $table->json('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servicios', function (Blueprint $table) {
            $table->dropColumn(['activation_token', 'plan_id', 'tier', 'subscription_id', 'metadata']);
        });
    }
};
