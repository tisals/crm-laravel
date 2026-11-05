<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SSO launch destination of an app. Nullable: apps living inside the
     * hub (or without a declared destination) keep it null.
     */
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->string('launch_url', 255)->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('launch_url');
        });
    }
};
