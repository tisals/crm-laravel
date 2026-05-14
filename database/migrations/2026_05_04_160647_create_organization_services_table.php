<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('service_name'); // 'diagnostico', 'chat', 'la-llave', etc.
            $table->enum('status', ['activo', 'suspendido', 'pendiente']);
            $table->string('schema_name')->nullable(); // for FastAPI multi-tenant
            $table->enum('plan_type', ['starter', 'pro', 'enterprise'])->nullable();
            $table->enum('payment_type', ['subscription', 'one_time'])->default('subscription'); // For Wompi recurring
            $table->integer('max_users')->nullable(); // License control
            $table->integer('active_users')->default(0); // Current active users
            $table->enum('license_status', ['trial', 'active', 'expired'])->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'service_name']); // One service per org
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_services');
    }
};
