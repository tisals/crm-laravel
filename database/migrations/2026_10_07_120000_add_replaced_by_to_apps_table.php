<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('replaced_by', 64)->nullable()->after('deleted_at');
            $table->index('replaced_by', 'idx_apps_replaced_by');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropIndex('idx_apps_replaced_by');
            $table->dropColumn('replaced_by');
        });
    }
};
