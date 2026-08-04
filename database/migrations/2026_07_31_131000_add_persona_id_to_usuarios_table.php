<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('usuarios', 'persona_id')) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->unsignedBigInteger('persona_id')->nullable()->after('id');
            $table->foreign('persona_id')->references('id')->on('personas')->nullOnDelete();
            $table->index('persona_id');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropForeign(['persona_id']);
            $table->dropColumn('persona_id');
        });
    }
};
