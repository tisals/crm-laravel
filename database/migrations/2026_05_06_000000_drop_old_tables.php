<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop pivot tables first (FK dependencies)
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('organization_services');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        // Note: personal_access_tokens is KEPT for Sanctum auth
    }

    public function down(): void
    {
        // This migration is destructive and non-reversible.
        // Old schema can be restored from version control.
    }
};
