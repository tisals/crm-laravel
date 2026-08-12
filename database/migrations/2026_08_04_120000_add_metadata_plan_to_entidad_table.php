<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('entidad', 'metadata')) {
            return;
        }

        Schema::table('entidad', function (Blueprint $table) {
            $table->longText('metadata')->nullable()->after('estado');
        });

        Schema::table('entidad', function (Blueprint $table) {
            $table->longText('plan')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('entidad', function (Blueprint $table) {
            $table->dropColumn(['plan', 'metadata']);
        });
    }
};
